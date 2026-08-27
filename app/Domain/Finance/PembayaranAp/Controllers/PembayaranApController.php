<?php

namespace App\Domain\Finance\PembayaranAp\Controllers;

use App\Domain\Finance\PembayaranAp\Requests\StorePembayaranApVoucherRequest;
use App\Domain\Finance\PembayaranAp\Resources\PembayaranApResource;
use App\Domain\Finance\PembayaranAp\Services\PembayaranApExportService;
use App\Domain\Finance\PembayaranAp\Services\PembayaranApService;
use App\Domain\Notification\Services\FinanceNotificationService;
use App\Http\Controllers\Controller;
use App\Models\PembayaranAp;
use App\Models\User;
use App\Support\Helpers\RoleHelper;
use App\Support\Helpers\SignatureBarcodeHelper;
use App\Support\Helpers\Terbilang;
use App\Support\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PembayaranApController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PembayaranApService $service,
        private readonly PembayaranApExportService $exportService,
        private readonly FinanceNotificationService $financeNotificationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView();

        $query = $this->scopedQuery($request)->with([
            'items.tagihanAp.vendorAp',
            'items.tagihanAp.perusahaan',
            'items.tagihanAp.karyawan',
            'items.vendorAp',
            'createdBy.karyawan',
        ]);

        $totalJumlah = (clone $query)->sum('jumlah_pembayaran');
        $list        = $query->latest('tanggal_pembayaran')->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data'    => $list->through(fn($p) => new PembayaranApResource($p))->items(),
            'meta'    => [
                'current_page' => $list->currentPage(),
                'last_page'    => $list->lastPage(),
                'per_page'     => $list->perPage(),
                'total'        => $list->total(),
                'total_jumlah' => $totalJumlah,
            ],
        ]);
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
     * dipakai FE untuk peringatan real-time XLSX vs CSV di modal Export Payment Voucher.
     * Hitung mengikuti logic yang sama dengan detailRowValues() di PembayaranApExportService
     * (1 baris per item barang, atau 1 baris kosong kalau tagihan tanpa item) supaya angkanya
     * konsisten dengan file yang sungguhan dihasilkan.
     */
    public function exportRowCount(Request $request): JsonResponse
    {
        $this->authorizeView();

        $vouchers = $this->scopedQuery($request)
            ->with(['items.tagihanAp.items'])
            ->get();

        $rowCount = $vouchers->sum(fn($voucher) => $voucher->items->sum(
            fn($alokasi) => max(1, $alokasi->tagihanAp?->items?->count() ?? 0)
        ));

        return $this->successResponse(['row_count' => $rowCount]);
    }

    private function streamXlsxExport(Request $request): BinaryFileResponse
    {
        $vouchers = $this->scopedQuery($request)
            ->with([
                'items.tagihanAp.items',
                'items.tagihanAp.vendorAp',
                'items.tagihanAp.perusahaan',
                'items.tagihanAp.karyawan',
                'items.vendorAp',
                'createdBy.karyawan',
            ])
            ->latest('tanggal_pembayaran')
            ->get();

        $spreadsheet = $this->exportService->build($vouchers);

        $temp = tempnam(sys_get_temp_dir(), 'pv_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'payment-voucher-ap-' . now()->format('Ymd-His') . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    /**
     * Export CSV — blok rekap voucher (kiri, plus kolom "Kategori" karena versi XLSX
     * memisahnya jadi 2 sheet Bahan Baku/Non Bahan Baku, sementara CSV cuma 1 file)
     * & blok detail item (kanan, dipisah 1 kolom kosong) ditulis berdampingan per
     * baris item. Pola sama seperti TagihanApController::streamCsvExport().
     */
    private function streamCsvExport(Request $request): StreamedResponse
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $vouchers = $this->scopedQuery($request)
            ->with([
                'items.tagihanAp.items',
                'items.tagihanAp.vendorAp',
                'items.tagihanAp.perusahaan',
                'items.tagihanAp.karyawan',
                'items.vendorAp',
                'createdBy.karyawan',
            ])
            ->latest('tanggal_pembayaran')
            ->get();

        $headerRekap = [
            'Tanggal Pembayaran', 'No Voucher', 'Kategori', 'Metode', 'Entitas', 'Vendor',
            'Jumlah Vendor', 'Jumlah Tagihan', 'Total Pembayaran', 'Keterangan', 'Dibuat Oleh', 'Dibuat Pada',
        ];
        $headerDetail = [
            'PIC AP', 'No Tagihan', 'No Invoice Vendor', 'Tanggal Tagihan', 'No PO', 'No Terima Barang',
            'Kode Barang', 'Nama Barang', 'Qty', 'Qty PO', 'Satuan', 'Harga Satuan', 'PPN', 'Subtotal Item',
            'Total Tagihan', 'Sisa Sebelum', 'Dibayar', 'Sisa Setelah', 'Keterangan Item',
        ];
        $header = array_merge($headerRekap, [''], $headerDetail);

        return response()->streamDownload(function () use ($vouchers, $header) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
            fputcsv($handle, $header, ';');

            foreach ($vouchers as $voucher) {
                $items = $voucher->items;

                $vendorNames = $items
                    ->map(fn($it) => $it->tagihanAp?->vendorAp?->nama_vendor ?? $it->vendorAp?->nama_vendor)
                    ->filter()->unique()->values();

                $entitasNames = $items
                    ->map(fn($it) => $it->tagihanAp?->perusahaan?->nama_singkatan_perusahaan)
                    ->filter()->unique()->values();

                $rekapRow = [
                    optional($voucher->tanggal_pembayaran)->format('d-m-Y') ?? '',
                    $voucher->no_referensi ?? '',
                    $voucher->kategori_voucher ?? '',
                    $voucher->metode_pembayaran ?? '',
                    $entitasNames->implode(', '),
                    $vendorNames->implode(', '),
                    $vendorNames->count(),
                    $items->count(),
                    (float) $voucher->jumlah_pembayaran,
                    $voucher->keterangan ?? '',
                    $voucher->createdBy?->karyawan?->nama_karyawan ?? $voucher->createdBy?->username ?? '',
                    optional($voucher->created_at)->format('d-m-Y H:i') ?? '',
                ];

                foreach ($items as $alokasi) {
                    $tagihan    = $alokasi->tagihanAp;
                    $barangItems = $tagihan?->items ?? collect();

                    $detailPrefix = [
                        $tagihan?->karyawan?->nama_karyawan ?? '',
                        $tagihan?->no_tagihan ?? '',
                        $tagihan?->no_invoice_vendor ?? '',
                        optional($tagihan?->tanggal_tagihan)->format('d-m-Y') ?? '',
                        $tagihan?->no_po ?? '',
                        $tagihan?->no_terima_barang ?? '',
                    ];
                    $detailSuffix = [
                        (float) ($tagihan?->total_tagihan ?? 0),
                        (float) $alokasi->sisa_sebelum,
                        (float) $alokasi->jumlah_dialokasikan,
                        (float) $alokasi->sisa_sesudah,
                    ];

                    if ($barangItems->isEmpty()) {
                        $detailRow = array_merge($detailPrefix, ['', '', '', '', '', '', '', ''], $detailSuffix, ['']);
                        fputcsv($handle, array_merge($rekapRow, [''], $detailRow), ';');

                        continue;
                    }

                    foreach ($barangItems as $barang) {
                        $itemValues = [
                            $barang->kode_barang ?? '',
                            $barang->nama_barang ?? '',
                            (float) $barang->qty,
                            $barang->qty_po !== null ? (float) $barang->qty_po : '',
                            $barang->satuan ?? '',
                            (float) $barang->harga_satuan,
                            (float) $barang->ppn,
                            (float) $barang->subtotal,
                        ];
                        $detailRow = array_merge($detailPrefix, $itemValues, $detailSuffix, [$barang->keterangan ?? '']);

                        fputcsv($handle, array_merge($rekapRow, [''], $detailRow), ';');
                    }
                }
            }

            fclose($handle);
        }, 'payment-voucher-ap-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function scopedQuery(Request $request): Builder
    {
        /** @var User $user */
        $user = auth()->user()->load('karyawan');

        $query = PembayaranAp::query()
            ->when($request->vendor_ap_id, fn($q, $v) =>
                $q->whereHas('items', fn($q) => $q->where('vendor_ap_id', $v))
            )
            ->when($request->metode_pembayaran, fn($q, $v) => $q->where('metode_pembayaran', $v))
            ->when($request->kategori_voucher, fn($q, $v) => $q->where('kategori_voucher', $v))
            ->when($request->tanggal_dari, fn($q, $v) => $q->whereDate('tanggal_pembayaran', '>=', $v))
            ->when($request->tanggal_sampai, fn($q, $v) => $q->whereDate('tanggal_pembayaran', '<=', $v));

        if ($user->karyawan && !RoleHelper::hasGlobalApAccess($user)) {
            $query->whereHas('items.tagihanAp', fn($q) =>
                $q->where('perusahaan_id', $user->karyawan->perusahaan_id)
            );
        }

        if (RoleHelper::isApStaff($user) && $user->karyawan) {
            $query->whereHas('items.vendorAp', fn($q) =>
                $q->where('karyawan_ap_id', $user->karyawan->id)
            );
        }

        return $query;
    }

    public function storeVoucher(StorePembayaranApVoucherRequest $request): JsonResponse
    {
        $this->authorizeOperate();

        $validated = $request->validated();
        $user      = $request->user()->loadMissing('karyawan');

        if (!RoleHelper::hasGlobalApAccess($user) && $user->karyawan) {
            $tagihanIds = collect($validated['alokasi'])->pluck('tagihan_ap_id')->unique();
            $luarScope  = \App\Models\TagihanAp::whereIn('id', $tagihanIds)
                ->where('perusahaan_id', '!=', $user->karyawan->perusahaan_id)
                ->exists();

            abort_if($luarScope, 403, 'Anda tidak memiliki akses untuk mencatat pembayaran tagihan ini.');
        }

        $pembayaran = $this->service->createVoucher($validated, $request->file('bukti_pembayaran'));

        Log::channel('security')->info('Voucher Pembayaran AP dicatat', [
            'user_id'        => $user->id,
            'pembayaran_id'  => $pembayaran->id,
            'jumlah_total'   => $pembayaran->jumlah_pembayaran,
            'jumlah_tagihan' => count($validated['alokasi']),
            'metode'         => $validated['metode_pembayaran'],
            'ip'             => $request->ip(),
        ]);

        return $this->createdResponse(
            new PembayaranApResource($pembayaran->load([
                'items.tagihanAp.vendorAp', 'items.tagihanAp.perusahaan', 'items.tagihanAp.karyawan', 'createdBy.karyawan',
            ])),
            'Voucher pembayaran berhasil dicatat'
        );
    }

    public function print(Request $request, int $id): Response|string
    {
        $this->authorizeView();

        $pembayaran = PembayaranAp::with([
            'items.tagihanAp.vendorAp',
            'items.tagihanAp.perusahaan',
            'createdBy.karyawan',
        ])->find($id);
        abort_if(!$pembayaran, 404, 'Data pembayaran tidak ditemukan');

        $vendorGroups = $pembayaran->items->groupBy('vendor_ap_id');
        $noVoucher    = $pembayaran->no_referensi ?: ('VP-' . str_pad((string) $pembayaran->id, 6, '0', STR_PAD_LEFT));
        $terbilang    = Terbilang::convert((int) $pembayaran->jumlah_pembayaran);

        $preparedByUser = $pembayaran->createdBy;
        $preparedByName = $preparedByUser?->karyawan?->nama_karyawan
            ?? $preparedByUser?->username
            ?? '___________________';

        $preparedPayload = SignatureBarcodeHelper::buildPembayaranApPreparedPayload(
            $pembayaran,
            $preparedByName,
            $noVoucher,
            $pembayaran->items->count(),
            $vendorGroups->count(),
        );

        $signatureData = [
            'prepared_by_name' => $preparedByName,
            'prepared_qr_src'  => SignatureBarcodeHelper::generateDataUri($preparedPayload, 250),
        ];

        $viewData = compact('pembayaran', 'vendorGroups', 'noVoucher', 'terbilang', 'signatureData');
        $filename = 'Payment-Voucher-AP-' . str_replace(['/', '\\', ' '], '-', $noVoucher) . '.pdf';

        if ($request->has('html')) {
            return view('finance.pembayaran-ap-print', $viewData)->render();
        }

        return Pdf::loadView('finance.pembayaran-ap-print', $viewData)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => false,
                'defaultFont'          => 'Arial',
                'dpi'                  => 96,
            ])
            ->stream($filename);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeOperate();

        $pembayaran = PembayaranAp::with(['tagihanAp', 'items.tagihanAp'])->find($id);
        abort_if(!$pembayaran, 404, 'Data pembayaran tidak ditemukan');

        $noReferensi = $pembayaran->no_referensi;
        $vendorApIds = $pembayaran->items->isNotEmpty()
            ? $pembayaran->items->pluck('tagihanAp.vendor_ap_id')->unique()->filter()->all()
            : array_filter([$pembayaran->tagihanAp?->vendor_ap_id]);

        $this->service->delete($pembayaran);

        $this->financeNotificationService->bankReconciliationAction(
            'batal_voucher_ap',
            'Payment Voucher AP dibatalkan',
            sprintf('%s membatalkan Payment Voucher AP %s.', auth()->user()?->name ?? '-', $noReferensi ?? '-'),
            vendorApIds: $vendorApIds,
        );

        return $this->successResponse(null, 'Pembayaran berhasil dihapus');
    }

    public function publicBukti(PembayaranAp $pembayaran): StreamedResponse
    {
        abort_if(!$pembayaran->bukti_path, 404);

        return Storage::disk($pembayaran->bukti_disk)->response(
            $pembayaran->bukti_path,
            $pembayaran->bukti_file_name,
            ['Content-Type' => $pembayaran->bukti_mime_type],
        );
    }

    public function cekReferensi(Request $request): JsonResponse
    {
        $this->authorizeView();

        $request->validate([
            'no_referensi' => ['required', 'string', 'max:100'],
        ]);

        $result = $this->service->cekDuplikatReferensi($request->no_referensi);

        return $this->successResponse($result);
    }

    private function authorizeView(): void
    {
        abort_if(!RoleHelper::canViewPembayaranAp(auth()->user()), 403, 'Tidak memiliki akses ke data pembayaran');
    }

    private function authorizeOperate(): void
    {
        abort_if(!RoleHelper::canOperatePembayaranAp(auth()->user()), 403, 'Tidak memiliki akses untuk mengelola pembayaran');
    }
}
