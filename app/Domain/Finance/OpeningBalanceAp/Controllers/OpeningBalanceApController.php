<?php

namespace App\Domain\Finance\OpeningBalanceAp\Controllers;

use App\Domain\Finance\OpeningBalanceAp\Requests\StoreOpeningBalanceApRequest;
use App\Domain\Finance\OpeningBalanceAp\Resources\OpeningBalanceApDetailResource;
use App\Domain\Finance\OpeningBalanceAp\Resources\OpeningBalanceApResource;
use App\Domain\Finance\OpeningBalanceAp\Services\OpeningBalanceApExportService;
use App\Domain\Finance\TagihanAp\Services\TagihanApService;
use App\Http\Controllers\Controller;
use App\Models\OpeningBalanceApDetailItem;
use App\Models\TagihanAp;
use App\Support\Helpers\ApFilterScope;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OpeningBalanceApController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TagihanApService $service,
        private readonly OpeningBalanceApExportService $exportService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView();

        $user    = auth()->user();
        $filters = $request->only([
            'search', 'status', 'vendor_ap_id', 'karyawan_id',
            'tanggal_dari', 'tanggal_sampai', 'approval_status', 'per_page',
        ]);
        $filters['is_opening_balance'] = true;
        ApFilterScope::apply($filters, $user);

        $list = $this->service->paginate($filters, with: [
            'vendorAp', 'perusahaan', 'karyawan',
            'submittedBy', 'createdBy', 'updatedBy',
        ]);

        return $this->paginatedResponse(
            $list->through(fn($tagihan) => new OpeningBalanceApResource($tagihan))
        );
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorizeView();

        $user    = auth()->user();
        $filters = $request->only([
            'search', 'status', 'vendor_ap_id', 'tanggal_dari', 'tanggal_sampai', 'approval_status',
        ]);
        $filters['is_opening_balance'] = true;
        ApFilterScope::apply($filters, $user);

        return $this->successResponse($this->service->getSummary($filters));
    }

    public function previewNo(Request $request): JsonResponse
    {
        $this->authorizeOperate();

        $payload = $request->validate([
            'tanggal' => ['required', 'date'],
        ]);

        return $this->successResponse([
            'no_tagihan' => $this->service->generateOpeningBalanceNoTagihan($payload['tanggal']),
        ]);
    }

    /**
     * Filter export Opening Balance AP, dipakai bersama oleh streamXlsxExport(),
     * streamCsvExport(), dan exportRowCount() supaya definisi filter tidak terduplikasi.
     */
    private function resolveExportFilters(Request $request): array
    {
        $filters = $request->only([
            'search', 'status', 'vendor_ap_id', 'karyawan_id',
            'tanggal_dari', 'tanggal_sampai', 'approval_status',
        ]);
        $filters['is_opening_balance'] = true;
        ApFilterScope::apply($filters, auth()->user());

        return $filters;
    }

    /**
     * Dispatcher format export. Default 'xlsx' supaya perilaku lama (FE yang belum
     * kirim query 'format') tidak berubah.
     */
    public function export(Request $request): BinaryFileResponse|StreamedResponse|JsonResponse
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
     * Jumlah baris (level-item) yang akan dihasilkan export untuk filter yang sama —
     * dipakai FE untuk peringatan real-time XLSX vs CSV di modal Export Opening Balance AP.
     * Sengaja ringan: cuma WHERE ... IN + COUNT, tanpa hydrate model.
     */
    public function exportRowCount(Request $request): JsonResponse
    {
        $this->authorizeView();

        $filters = $this->resolveExportFilters($request);
        $tagihanIds = $this->service->getExportIds($filters);

        $rowCount = OpeningBalanceApDetailItem::whereHas(
            'obDetail',
            fn ($q) => $q->whereIn('tagihan_ap_id', $tagihanIds)
        )->count();

        return $this->successResponse(['row_count' => $rowCount]);
    }

    private function streamXlsxExport(Request $request): BinaryFileResponse
    {
        $filters = $this->resolveExportFilters($request);

        $tagihanList = $this->service->getAll($filters, [
            'vendorAp', 'perusahaan', 'karyawan',
            'openingBalanceApDetails.items.barang',
            'submittedBy.karyawan', 'approvedBy.karyawan', 'rejectedBy.karyawan', 'createdBy.karyawan',
        ]);

        $spreadsheet = $this->exportService->build($tagihanList);

        $temp = tempnam(sys_get_temp_dir(), 'obap_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'opening-balance-ap-' . now()->format('Ymd-His') . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    /**
     * Export CSV — gabungkan blok rekap (header OB, tanpa rincian) dengan blok
     * detail+item (kolom "No OB"/"Vendor" tidak diulang, sudah ada di blok rekap)
     * jadi 1 baris per item, kolom berdampingan dipisah 1 kolom kosong. Meniru
     * OpeningBalanceController::streamCsvExport() (OB AR). Tagihan tanpa rincian,
     * atau rincian tanpa item, tetap ditulis minimal 1 baris (padding kolom kosong)
     * supaya tidak hilang dari CSV.
     */
    private function streamCsvExport(Request $request): StreamedResponse
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $filters = $this->resolveExportFilters($request);

        $tagihanList = $this->service->getAll($filters, [
            'vendorAp', 'perusahaan', 'karyawan',
            'openingBalanceApDetails.items.barang',
            'submittedBy.karyawan', 'approvedBy.karyawan', 'rejectedBy.karyawan', 'createdBy.karyawan',
        ]);

        $headerRekap = [
            'No OB', 'Vendor', 'Kode Vendor', 'Entitas', 'PIC AP', 'Tanggal OB', 'Jatuh Tempo',
            'Saldo Awal', 'Terbayar', 'Penyesuaian', 'Sisa Hutang', 'Status', 'Approval', 'Keterangan',
            'Diajukan Oleh', 'Diajukan Pada', 'Disetujui Oleh', 'Disetujui Pada',
            'Ditolak Oleh', 'Ditolak Pada', 'Dibuat Oleh', 'Dibuat Pada',
        ];
        $headerDetail = [
            'No Invoice Asal', 'Tanggal Invoice Asal', 'Deskripsi', 'Jumlah Tagihan Asal', 'Sisa Tagihan Asal',
            'Kode Barang', 'Nama Barang', 'Qty', 'Satuan', 'Harga Satuan', 'Subtotal Item', 'Keterangan',
        ];
        $header = array_merge($headerRekap, [''], $headerDetail);

        $detailBlank = array_fill(0, count($headerDetail), '');

        return response()->streamDownload(function () use ($tagihanList, $header, $detailBlank) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
            // Titik koma, bukan koma — locale Excel Indonesia pakai ";" sebagai list
            // separator sistem, jadi file terbuka langsung terbagi rapi per kolom.
            fputcsv($handle, $header, ';');

            foreach ($tagihanList as $tagihan) {
                $rekapRow = [
                    $tagihan->no_tagihan ?? '',
                    $tagihan->vendorAp?->nama_vendor ?? '',
                    $tagihan->vendorAp?->kode_vendor ?? '',
                    $tagihan->perusahaan?->nama_singkatan_perusahaan ?? '',
                    $tagihan->karyawan?->nama_karyawan ?? '',
                    optional($tagihan->tanggal_tagihan)->format('d-m-Y') ?? '',
                    optional($tagihan->tanggal_jatuh_tempo)->format('d-m-Y') ?? '',
                    (float) $tagihan->total_tagihan,
                    (float) $tagihan->total_pembayaran,
                    (float) $tagihan->total_penyesuaian,
                    (float) $tagihan->sisa_tagihan,
                    $tagihan->status ?? '',
                    $tagihan->approval_status ?? '',
                    $tagihan->keterangan ?? '',
                    $tagihan->submittedBy?->karyawan?->nama_karyawan ?? $tagihan->submittedBy?->username ?? '',
                    optional($tagihan->submitted_at)->format('d-m-Y H:i') ?? '',
                    $tagihan->approvedBy?->karyawan?->nama_karyawan ?? $tagihan->approvedBy?->username ?? '',
                    optional($tagihan->approved_at)->format('d-m-Y H:i') ?? '',
                    $tagihan->rejectedBy?->karyawan?->nama_karyawan ?? $tagihan->rejectedBy?->username ?? '',
                    optional($tagihan->rejected_at)->format('d-m-Y H:i') ?? '',
                    $tagihan->createdBy?->karyawan?->nama_karyawan ?? $tagihan->createdBy?->username ?? '',
                    optional($tagihan->created_at)->format('d-m-Y H:i') ?? '',
                ];

                if ($tagihan->openingBalanceApDetails->isEmpty()) {
                    fputcsv($handle, array_merge($rekapRow, [''], $detailBlank), ';');

                    continue;
                }

                foreach ($tagihan->openingBalanceApDetails as $detail) {
                    $detailPrefix = [
                        $detail->no_invoice_asal ?? '',
                        optional($detail->tanggal_invoice_asal)->format('d-m-Y') ?? '',
                        $detail->deskripsi ?? '',
                        (float) $detail->jumlah_tagihan_asal,
                        (float) $detail->sisa_tagihan_asal,
                    ];
                    $detailSuffix = [
                        $detail->keterangan ?? '',
                    ];

                    $items = $detail->items;

                    if ($items->isEmpty()) {
                        $detailRow = array_merge($detailPrefix, ['', '', '', '', '', ''], $detailSuffix);
                        fputcsv($handle, array_merge($rekapRow, [''], $detailRow), ';');

                        continue;
                    }

                    foreach ($items as $item) {
                        $itemValues = [
                            $item->kode_barang ?? $item->barang?->kode_barang ?? '',
                            $item->nama_barang ?? '',
                            (float) $item->qty,
                            $item->satuan ?? '',
                            (float) $item->harga_satuan,
                            (float) $item->subtotal,
                        ];
                        $detailRow = array_merge($detailPrefix, $itemValues, $detailSuffix);

                        fputcsv($handle, array_merge($rekapRow, [''], $detailRow), ';');
                    }
                }
            }

            fclose($handle);
        }, 'opening-balance-ap-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function store(StoreOpeningBalanceApRequest $request): JsonResponse
    {
        $this->authorizeOperate();

        $tagihan = $this->service->createOpeningBalance($request->validated());

        return $this->createdResponse(
            new OpeningBalanceApResource($tagihan),
            'Opening balance AP berhasil diajukan untuk persetujuan'
        );
    }

    public function update(StoreOpeningBalanceApRequest $request, int $id): JsonResponse
    {
        $this->authorizeOperate();

        $tagihan = $this->findOpeningBalanceOrFail($id);
        $updated = $this->service->updateOpeningBalance($tagihan, $request->validated());

        return $this->successResponse(
            new OpeningBalanceApResource($updated),
            'Opening balance AP berhasil diperbarui'
        );
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeView();

        $tagihan = $this->findOpeningBalanceOrFail($id);

        return $this->successResponse(new OpeningBalanceApResource($tagihan));
    }

    public function details(int $id): JsonResponse
    {
        $this->authorizeView();

        $tagihan = $this->findOpeningBalanceOrFail($id);

        return $this->successResponse(
            OpeningBalanceApDetailResource::collection(
                $tagihan->openingBalanceApDetails()->with('items.barang')->orderBy('tanggal_invoice_asal')->get()
            )
        );
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $this->authorizeApprove();

        $payload = $request->validate([
            'note' => ['nullable', 'string'],
        ]);

        $tagihan = $this->findOpeningBalanceOrFail($id);
        $updated = $this->service->approveOpeningBalance($tagihan, $payload['note'] ?? null);

        Log::channel('security')->info('Opening balance AP disetujui', [
            'user_id'    => auth()->id(),
            'tagihan_id' => $tagihan->id,
            'no_tagihan' => $tagihan->no_tagihan,
            'ip'         => $request->ip(),
        ]);

        return $this->successResponse(
            new OpeningBalanceApResource($updated),
            'Opening balance AP berhasil disetujui'
        );
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $this->authorizeApprove();

        $payload = $request->validate([
            'note' => ['required', 'string'],
        ]);

        $tagihan = $this->findOpeningBalanceOrFail($id);
        $updated = $this->service->rejectOpeningBalance($tagihan, $payload['note']);

        Log::channel('security')->info('Opening balance AP ditolak', [
            'user_id'    => auth()->id(),
            'tagihan_id' => $tagihan->id,
            'no_tagihan' => $tagihan->no_tagihan,
            'note'       => $payload['note'],
            'ip'         => $request->ip(),
        ]);

        return $this->successResponse(
            new OpeningBalanceApResource($updated),
            'Opening balance AP berhasil ditolak'
        );
    }

    public function resubmit(Request $request, int $id): JsonResponse
    {
        $this->authorizeOperate();

        $payload = $request->validate([
            'note' => ['nullable', 'string'],
        ]);

        $tagihan = $this->findOpeningBalanceOrFail($id);
        $updated = $this->service->resubmitOpeningBalance($tagihan, $payload['note'] ?? null);

        return $this->successResponse(
            new OpeningBalanceApResource($updated),
            'Opening balance AP berhasil diajukan ulang'
        );
    }

    private function findOpeningBalanceOrFail(int $id): TagihanAp
    {
        $tagihan = $this->service->findOrFail($id);
        abort_if(!$tagihan->is_opening_balance, 404, 'Opening balance AP tidak ditemukan');

        return $tagihan;
    }

    private function authorizeView(): void
    {
        abort_if(
            !RoleHelper::canViewOpeningBalanceAp(auth()->user()),
            403,
            'Tidak memiliki akses ke data opening balance AP'
        );
    }

    private function authorizeOperate(): void
    {
        abort_if(
            !RoleHelper::canOperateOpeningBalanceAp(auth()->user()),
            403,
            'Tidak memiliki akses untuk mengelola opening balance AP'
        );
    }

    private function authorizeApprove(): void
    {
        abort_if(
            !RoleHelper::canApproveOpeningBalanceAp(auth()->user()),
            403,
            'Tidak memiliki akses untuk menyetujui opening balance AP'
        );
    }
}
