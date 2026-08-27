<?php

namespace App\Domain\Finance\TagihanAp\Controllers;

use App\Domain\Finance\TagihanAp\DTO\TagihanApDTO;
use App\Domain\Finance\TagihanAp\Requests\StoreTagihanApRequest;
use App\Domain\Finance\TagihanAp\Requests\UpdateTagihanApRequest;
use App\Domain\Finance\TagihanAp\Resources\TagihanApListResource;
use App\Domain\Finance\TagihanAp\Resources\TagihanApResource;
use App\Domain\Finance\TagihanAp\Services\TagihanApExportService;
use App\Domain\Finance\TagihanAp\Services\TagihanApService;
use App\Http\Controllers\Controller;
use App\Models\TagihanAp;
use App\Models\TagihanApItem;
use App\Support\Helpers\ApFilterScope;
use App\Support\Helpers\RoleHelper;
use App\Support\Helpers\SignatureBarcodeHelper;
use App\Support\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TagihanApController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TagihanApService $service,
        private readonly TagihanApExportService $exportService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView();

        $user    = auth()->user();
        $filters = $request->only([
            'search', 'status', 'approval_status', 'vendor_ap_id', 'karyawan_id',
            'tanggal_dari', 'tanggal_sampai', 'per_page',
        ]);
        $filters['is_opening_balance'] = false;
        ApFilterScope::apply($filters, $user);

        $list      = $this->service->paginate($filters);
        $lockedIds = $this->batchLoadEbLockedIds($list->getCollection());

        return $this->paginatedResponse(
            $list->through(fn($t) => new TagihanApListResource($t, $lockedIds))
        );
    }

    /**
     * Batch-load id tagihan yang berada dalam periode ending balance AP LOCKED,
     * satu query untuk seluruh halaman (hindari N+1 per baris).
     */
    private function batchLoadEbLockedIds(\Illuminate\Support\Collection $tagihanList): array
    {
        $ids = $tagihanList->pluck('id')->all();
        if (empty($ids)) {
            return [];
        }

        return DB::table('tb_tagihan_ap as t')
            ->join('tb_ending_balance_ap as eb', function ($join) {
                $join->on('t.vendor_ap_id', '=', 'eb.vendor_ap_id')
                    ->on('t.perusahaan_id', '=', 'eb.perusahaan_id')
                    ->whereColumn('t.tanggal_tagihan', '>=', 'eb.periode_awal')
                    ->whereColumn('t.tanggal_tagihan', '<=', 'eb.periode_akhir');
            })
            ->where('eb.status', 'LOCKED')
            ->whereIn('t.id', $ids)
            ->pluck('t.id')
            ->all();
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorizeView();

        $user    = auth()->user();
        $filters = $request->only(['search', 'status', 'approval_status', 'vendor_ap_id', 'tanggal_dari', 'tanggal_sampai']);
        $filters['is_opening_balance'] = false;
        ApFilterScope::apply($filters, $user);

        return $this->successResponse($this->service->getSummary($filters));
    }

    public function previewNo(Request $request): JsonResponse
    {
        $this->authorizeOperate();

        $payload = $request->validate([
            'vendor_ap_id' => ['required', 'integer', 'exists:tb_vendor_ap,id'],
            'tanggal'      => ['required', 'date'],
        ]);

        return $this->successResponse([
            'no_tagihan' => $this->service->generateNoTagihan($payload['tanggal']),
        ]);
    }

    public function outstanding(Request $request): JsonResponse
    {
        $this->authorizeOperate();

        $validated = $request->validate([
            'vendor_ap_id' => ['nullable', 'integer', 'exists:tb_vendor_ap,id'],
            'search'       => ['nullable', 'string', 'max:100'],
            'tanggal'      => ['nullable', 'date'],
        ]);

        $user    = auth()->user();
        $filters = [];
        ApFilterScope::apply($filters, $user);

        $query = TagihanAp::with(['items.barang', 'vendorAp'])
            ->whereIn('status', ['DITERIMA', 'SEBAGIAN'])
            ->where('is_opening_balance', false)
            ->when($validated['vendor_ap_id'] ?? null, fn($q, $v) => $q->where('vendor_ap_id', $v))
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('perusahaan_id', $v))
            ->when($filters['pic_ap_karyawan_id'] ?? null, fn($q, $v) =>
                $q->whereHas('vendorAp', fn($q) => $q->where('karyawan_ap_id', $v))
            )
            ->when($validated['search'] ?? null, fn($q, $v) => $q->where(fn($q) => $q
                ->where('no_tagihan', 'like', "%{$v}%")
                ->orWhere('no_invoice_vendor', 'like', "%{$v}%")
                ->orWhereHas('vendorAp', fn($q) => $q
                    ->where('nama_vendor', 'like', "%{$v}%")
                    ->orWhere('kode_vendor', 'like', "%{$v}%")
                )
            ));

        if (!empty($validated['tanggal'])) {
            $query->where('tanggal_tagihan', '<', $validated['tanggal']);
        }

        $tagihanList = $query->orderBy('tanggal_tagihan')->orderBy('id')->get();

        // Fallback: item lama bisa punya barang_id kosong tapi kode_barang valid.
        // Cari master barang berdasarkan kode_barang agar tetap tersambung.
        $kodeBarangTanpaId = $tagihanList->flatMap(fn($t) => $t->items)
            ->whereNull('barang_id')
            ->pluck('kode_barang')
            ->filter()
            ->unique()
            ->values();

        $barangByKode = $kodeBarangTanpaId->isNotEmpty()
            ? \App\Models\Barang::whereIn('kode_barang', $kodeBarangTanpaId)->get()->keyBy('kode_barang')
            : collect();

        $tagihanList = $tagihanList->map(fn($t) => [
                'id'                => $t->id,
                'no_tagihan'        => $t->no_tagihan,
                'no_invoice_vendor' => $t->no_invoice_vendor,
                'tanggal_tagihan'   => $t->tanggal_tagihan?->toDateString(),
                'subtotal'          => (float) $t->subtotal,
                'total_tagihan'     => (float) $t->total_tagihan,
                'sisa_tagihan'      => max(0.0, (float) $t->sisa_tagihan),
                'status'            => $t->status,
                'keterangan'        => $t->keterangan,
                'vendor_ap_id'      => $t->vendor_ap_id,
                'vendor'            => $t->vendorAp?->nama_vendor,
                'kode_vendor'       => $t->vendorAp?->kode_vendor,
                'items'             => $t->items->map(function ($item) use ($barangByKode) {
                    $fallbackBarang = !$item->barang_id && $item->kode_barang
                        ? $barangByKode->get($item->kode_barang)
                        : null;

                    return [
                        'barang_id'    => $item->barang_id ?? $fallbackBarang?->id,
                        'kode_barang'  => $item->kode_barang ?? $item->barang?->kode_barang ?? '',
                        'nama_barang'  => $item->nama_barang,
                        'qty'          => (float) $item->qty,
                        'satuan'       => $item->satuan ?? 'pcs',
                        'harga_satuan' => (float) $item->harga_satuan,
                        'subtotal'     => (float) $item->subtotal,
                        'keterangan'   => $item->keterangan ?? '',
                    ];
                })->values()->all(),
            ]);

        return $this->successResponse($tagihanList);
    }

    /**
     * Filter export Tagihan AP, dipakai bersama oleh streamXlsxExport(),
     * streamCsvExport(), dan exportRowCount() supaya definisi filter tidak terduplikasi.
     */
    private function resolveExportFilters(Request $request): array
    {
        $filters = $request->only([
            'search', 'status', 'approval_status', 'vendor_ap_id', 'karyawan_id',
            'tanggal_dari', 'tanggal_sampai',
        ]);
        $filters['is_opening_balance'] = false;
        ApFilterScope::apply($filters, auth()->user());

        return $filters;
    }

    /**
     * Dispatcher format export. Default 'xlsx' supaya perilaku lama (FE yang belum
     * kirim query 'format') tidak berubah.
     */
    public function exportExcel(Request $request): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $this->authorizeView();

        $format = strtolower((string) $request->query('format', 'xlsx'));

        if ($format === 'csv') {
            return $this->streamCsvExport($request);
        }

        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        return $this->streamXlsxExport($request);
    }

    /**
     * Jumlah baris detail item yang akan dihasilkan export untuk filter yang sama —
     * dipakai FE untuk peringatan real-time XLSX vs CSV di modal Export Tagihan AP.
     */
    public function exportRowCount(Request $request): JsonResponse
    {
        $this->authorizeView();

        $filters    = $this->resolveExportFilters($request);
        $tagihanIds = $this->service->getExportIds($filters);
        $rowCount   = TagihanApItem::whereIn('tagihan_ap_id', $tagihanIds)->count();

        return $this->successResponse(['row_count' => $rowCount]);
    }

    private function streamXlsxExport(Request $request): BinaryFileResponse
    {
        $filters = $this->resolveExportFilters($request);

        $tagihanList = $this->service->getAll($filters, [
            'items.barang',
            'vendorAp.karyawanAp',
            'perusahaan',
            'karyawan',
            'createdBy.karyawan',
        ]);

        $spreadsheet = $this->exportService->build($tagihanList);

        $temp = tempnam(sys_get_temp_dir(), 'tgh_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'tagihan-ap-' . now()->format('Ymd-His') . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    /**
     * Export CSV — blok rekap tagihan (kiri) & blok detail item (kanan, dipisah 1
     * kolom kosong) ditulis berdampingan pada baris yang sama, meniru tampilan
     * 1-sheet versi XLSX (lihat TagihanApExportService). Pola sama seperti
     * OpeningBalanceApController::streamCsvExport().
     */
    private function streamCsvExport(Request $request): StreamedResponse
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $filters = $this->resolveExportFilters($request);

        $tagihanList = $this->service->getAll($filters, [
            'items.barang',
            'vendorAp.karyawanAp',
            'perusahaan',
            'karyawan',
            'createdBy.karyawan',
        ]);

        $headerRekap = [
            'No Tagihan', 'No Invoice Vendor', 'Vendor', 'Kode Vendor', 'Entitas', 'PIC AP',
            'Tanggal Tagihan', 'Tanggal Jatuh Tempo', 'No PO', 'No Terima Barang',
            'Subtotal', 'PPN Masukan', 'PPH23', 'Total Tagihan', 'Total Pembayaran', 'Sisa Tagihan',
            'Status', 'Approval', 'Keterangan', 'Dibuat Oleh', 'Dibuat Pada',
        ];
        $headerDetail = [
            'Kode Barang', 'Nama Barang', 'Qty', 'Qty PO', 'Satuan', 'Harga Satuan', 'PPN',
            'Subtotal Item', 'Status Terima PO', 'Qty Tolak', 'Keterangan Tolak', 'Keterangan Item',
        ];
        $header = array_merge($headerRekap, [''], $headerDetail);

        $detailBlank = array_fill(0, count($headerDetail), '');

        return response()->streamDownload(function () use ($tagihanList, $header, $detailBlank) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
            fputcsv($handle, $header, ';');

            foreach ($tagihanList as $tagihan) {
                $rekapRow = [
                    $tagihan->no_tagihan ?? '',
                    $tagihan->no_invoice_vendor ?? '',
                    $tagihan->vendorAp?->nama_vendor ?? '',
                    $tagihan->vendorAp?->kode_vendor ?? '',
                    $tagihan->perusahaan?->nama_singkatan_perusahaan ?? '',
                    $tagihan->karyawan?->nama_karyawan ?? '',
                    optional($tagihan->tanggal_tagihan)->format('d-m-Y') ?? '',
                    optional($tagihan->tanggal_jatuh_tempo)->format('d-m-Y') ?? '',
                    $tagihan->no_po ?? '',
                    $tagihan->no_terima_barang ?? '',
                    (float) $tagihan->subtotal,
                    (float) $tagihan->ppn_masukan,
                    (float) $tagihan->pph23,
                    (float) $tagihan->total_tagihan,
                    (float) $tagihan->total_pembayaran,
                    (float) $tagihan->sisa_tagihan,
                    $tagihan->status ?? '',
                    $tagihan->approval_status ?? '',
                    $tagihan->keterangan ?? '',
                    $tagihan->createdBy?->karyawan?->nama_karyawan ?? $tagihan->createdBy?->username ?? '',
                    optional($tagihan->created_at)->format('d-m-Y H:i') ?? '',
                ];

                if ($tagihan->items->isEmpty()) {
                    fputcsv($handle, array_merge($rekapRow, [''], $detailBlank), ';');

                    continue;
                }

                foreach ($tagihan->items as $item) {
                    $detailRow = [
                        $item->kode_barang ?? '',
                        $item->nama_barang ?? '',
                        (float) $item->qty,
                        $item->qty_po !== null ? (float) $item->qty_po : '',
                        $item->satuan ?? '',
                        (float) $item->harga_satuan,
                        (float) $item->ppn,
                        (float) $item->subtotal,
                        $item->status_detail_terima_po ?? '',
                        $item->qty_tolak !== null ? (float) $item->qty_tolak : '',
                        $item->keterangan_tolak ?? '',
                        $item->keterangan ?? '',
                    ];

                    fputcsv($handle, array_merge($rekapRow, [''], $detailRow), ';');
                }
            }

            fclose($handle);
        }, 'tagihan-ap-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function store(StoreTagihanApRequest $request): JsonResponse
    {
        $this->authorizeOperate();

        $tagihan = $this->service->create(TagihanApDTO::fromRequest($request->validated()));
        return $this->createdResponse(new TagihanApResource($tagihan), 'Tagihan berhasil diajukan untuk persetujuan');
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeView();

        $tagihan = $this->service->findOrFail($id);
        $this->authorizeTagihanAccess($tagihan);
        return $this->successResponse(new TagihanApResource($tagihan));
    }

    public function print(Request $request, int $id): Response|string
    {
        $this->authorizeView();

        $tagihan = $this->service->findForPrintOrFail($id);
        $this->authorizeTagihanAccess($tagihan);
        $viewData = $this->buildPrintViewData($tagihan);

        $filename = 'Tagihan-AP-' . str_replace(['/', '\\', ' '], '-', $tagihan->no_tagihan) . '.pdf';

        if ($request->has('html')) {
            return view('finance.tagihan-ap-print', $viewData)->render();
        }

        return Pdf::loadView('finance.tagihan-ap-print', $viewData)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => false,
                'defaultFont'          => 'Arial',
                'dpi'                  => 96,
            ])
            ->stream($filename);
    }

    public function publicPrint(string $token): Response
    {
        $tagihan = TagihanAp::with([
            'vendorAp', 'perusahaan', 'karyawan.perusahaan', 'items', 'pembayarans',
            'createdBy.karyawan', 'submittedBy.karyawan', 'approvedBy.karyawan',
            'openingBalanceApDetails.items',
        ])
            ->where('prepared_token', $token)
            ->firstOrFail();
        $viewData = $this->buildPrintViewData($tagihan);

        $filename = 'Tagihan-AP-' . str_replace(['/', '\\', ' '], '-', $tagihan->no_tagihan) . '.pdf';

        return Pdf::loadView('finance.tagihan-ap-print', $viewData)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => false,
                'defaultFont'          => 'Arial',
                'dpi'                  => 96,
            ])
            ->stream($filename);
    }

    private function buildPrintViewData(TagihanAp $tagihan): array
    {
        abort_if(
            $tagihan->is_opening_balance && $tagihan->approval_status !== 'APPROVED',
            422,
            'Opening balance AP belum disetujui, dokumen belum dapat dicetak'
        );

        $signatureData = $this->buildSignatureData($tagihan);

        $tagihanBerjalanInPeriod = collect();
        if ($tagihan->is_opening_balance && $tagihan->vendor_ap_id && $tagihan->tanggal_tagihan) {
            $bulanAwal  = \Carbon\Carbon::parse($tagihan->tanggal_tagihan)->startOfMonth();
            $bulanAkhir = \Carbon\Carbon::parse($tagihan->tanggal_tagihan)->endOfMonth();

            $tagihanBerjalanInPeriod = TagihanAp::query()
                ->where('vendor_ap_id', $tagihan->vendor_ap_id)
                ->where('perusahaan_id', $tagihan->perusahaan_id)
                ->where('is_opening_balance', false)
                ->whereBetween('tanggal_tagihan', [$bulanAwal->toDateString(), $bulanAkhir->toDateString()])
                ->orderBy('tanggal_tagihan', 'asc')
                ->get();
        }

        if ($tagihanBerjalanInPeriod->isNotEmpty()) {
            $tagihanBerjalanInPeriod->load([
                'vendorAp', 'perusahaan', 'karyawan.perusahaan', 'items.barang',
                'createdBy.karyawan', 'submittedBy.karyawan',
            ]);
        }

        $tagihanBerjalanSignatureData = $tagihanBerjalanInPeriod
            ->mapWithKeys(fn ($t) => [$t->id => $this->buildSignatureData($t)])
            ->all();

        return compact('tagihan', 'signatureData', 'tagihanBerjalanInPeriod', 'tagihanBerjalanSignatureData');
    }

    private function buildSignatureData(TagihanAp $tagihan): array
    {
        $preparedByUser = $tagihan->submittedBy ?: $tagihan->createdBy;
        $preparedByName = $preparedByUser?->karyawan?->nama_karyawan
            ?? $preparedByUser?->username
            ?? '___________________';

        $preparedPayload = $tagihan->is_opening_balance
            ? SignatureBarcodeHelper::buildOpeningBalanceApPreparedPayload($tagihan, $preparedByName)
            : SignatureBarcodeHelper::buildTagihanApPreparedPayload($tagihan, $preparedByName);

        $signatureData = [
            'prepared_by_name' => $preparedByName,
            'prepared_qr_src'  => SignatureBarcodeHelper::generateDataUri($preparedPayload, 250),
        ];

        if ($tagihan->is_opening_balance && $tagihan->approvedBy) {
            $approvedByName = $tagihan->approvedBy->karyawan?->nama_karyawan
                ?? $tagihan->approvedBy->username
                ?? '___________________';

            $approvedPayload = SignatureBarcodeHelper::buildOpeningBalanceApApprovedPayload($tagihan, $approvedByName);

            $signatureData['approved_by_name'] = $approvedByName;
            $signatureData['approved_qr_src']  = SignatureBarcodeHelper::generateDataUri($approvedPayload, 250);
        }

        return $signatureData;
    }

    public function update(UpdateTagihanApRequest $request, int $id): JsonResponse
    {
        $this->authorizeOperate();

        $tagihan = $this->service->findOrFail($id);
        $this->authorizeTagihanAccess($tagihan);
        $updated = $this->service->update($tagihan, TagihanApDTO::fromRequest($request->validated()));
        return $this->successResponse(new TagihanApResource($updated), 'Tagihan berhasil diperbarui');
    }

    public function approvalLogs(int $id): JsonResponse
    {
        $this->authorizeView();

        $tagihan = TagihanAp::findOrFail($id);
        $this->authorizeTagihanAccess($tagihan);
        $logs    = $tagihan->approvalLogs()->with('actor')->get();

        return $this->successResponse($logs->map(fn($log) => [
            'id'         => $log->id,
            'action'     => $log->action,
            'note'       => $log->note,
            'actor_id'   => $log->actor_id,
            'actor_name' => $log->actor?->username,
            'created_at' => $log->created_at?->toIso8601String(),
        ])->values());
    }

    public function pembayaran(int $id): JsonResponse
    {
        $this->authorizeView();

        $tagihan = TagihanAp::findOrFail($id);
        $this->authorizeTagihanAccess($tagihan);
        $rows    = $tagihan->pembayaranApItems()->with('pembayaranAp.createdBy')->get();

        return $this->successResponse($rows->map(fn($item) => [
            'id'                 => $item->pembayaranAp->id,
            'tanggal_pembayaran' => $item->pembayaranAp->tanggal_pembayaran?->format('d-m-Y'),
            'jumlah_pembayaran'  => (float) $item->jumlah_dialokasikan,
            'metode_pembayaran'  => $item->pembayaranAp->metode_pembayaran,
            'no_referensi'       => $item->pembayaranAp->no_referensi,
            'keterangan'         => $item->pembayaranAp->keterangan,
            'created_by_name'    => $item->pembayaranAp->createdBy?->username,
            'created_at'         => $item->pembayaranAp->created_at?->toIso8601String(),
            'bukti_file_name'    => $item->pembayaranAp->bukti_file_name,
            'bukti_mime_type'    => $item->pembayaranAp->bukti_mime_type,
        ]));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeOperate();

        $tagihan = $this->service->findOrFail($id);
        $this->authorizeTagihanAccess($tagihan);
        $this->service->delete($tagihan);
        return $this->successResponse(null, 'Tagihan berhasil dihapus');
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->authorizeOperate();

        $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = $this->service->bulkDelete($request->ids);

        return $this->successResponse(
            ['deleted' => $deleted],
            "{$deleted} tagihan berhasil dihapus"
        );
    }

    private function authorizeView(): void
    {
        abort_if(!RoleHelper::canViewTagihanAp(auth()->user()), 403, 'Tidak memiliki akses ke data tagihan');
    }

    private function authorizeOperate(): void
    {
        abort_if(!RoleHelper::canOperateTagihanAp(auth()->user()), 403, 'Tidak memiliki akses untuk mengelola tagihan');
    }

    /**
     * Scoping akses tagihan per-vendor untuk AP staff (mirror
     * VendorApController::authorizePicApVendor()) — authorizeView()/authorizeOperate()
     * hanya mengecek role, bukan kepemilikan vendor, sehingga tanpa ini satu staff AP
     * bisa lihat/ubah/hapus tagihan milik vendor yang ditugaskan ke staff AP lain.
     */
    private function authorizeTagihanAccess(TagihanAp $tagihan): void
    {
        $user = auth()->user();
        if (!RoleHelper::isApStaff($user)) {
            return;
        }

        abort_if(
            (int) $user->karyawan_id !== (int) $tagihan->vendorAp?->karyawan_ap_id,
            403,
            'Anda hanya dapat mengakses tagihan vendor yang ditugaskan kepada Anda'
        );
    }

}
