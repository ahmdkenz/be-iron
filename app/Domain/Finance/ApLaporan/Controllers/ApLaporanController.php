<?php

namespace App\Domain\Finance\ApLaporan\Controllers;

use App\Domain\Finance\ApLaporan\Services\ApLaporanExportService;
use App\Domain\Finance\ApLaporan\Services\ApLaporanService;
use App\Http\Controllers\Controller;
use App\Support\Helpers\ApFilterScope;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ApLaporanController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ApLaporanService $service,
        private readonly ApLaporanExportService $exportService,
    ) {}

    // ─── Hutang per Vendor ──────────────────────────────────────────

    public function hutangVendor(Request $request): JsonResponse
    {
        $this->authorizeView();

        $request->validate([
            'tanggal_dari'   => ['nullable', 'date'],
            'tanggal_sampai' => ['nullable', 'date', 'after_or_equal:tanggal_dari'],
            'vendor_ap_id'   => ['nullable', 'integer', 'exists:tb_vendor_ap,id'],
            'perusahaan_id'  => ['nullable', 'integer', 'exists:tb_perusahaan,id'],
            'as_of_date'     => ['nullable', 'date'],
            'page'           => ['nullable', 'integer', 'min:1'],
            'per_page'       => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filters = $this->scopedFilters($request, [
            'tanggal_dari', 'tanggal_sampai', 'vendor_ap_id', 'perusahaan_id', 'as_of_date',
        ]);

        $report = $this->service->getHutangVendor($filters);

        if ($perPage = $request->integer('per_page')) {
            ['items' => $report['rows'], 'meta' => $report['meta']]
                = $this->paginateArray($report['rows'], $request->integer('page', 1), $perPage);
        }

        return $this->successResponse($report);
    }

    public function hutangVendorExportExcel(Request $request): BinaryFileResponse|JsonResponse
    {
        $this->authorizeView();

        if ($zipError = $this->ensureZipAvailable()) {
            return $zipError;
        }

        $filters = $this->scopedFilters($request, [
            'tanggal_dari', 'tanggal_sampai', 'vendor_ap_id', 'perusahaan_id', 'as_of_date',
        ]);
        $report = $this->service->getHutangVendor($filters);

        $spreadsheet = $this->exportService->buildHutangVendor($report);

        return $this->streamXlsx($spreadsheet, 'hutang-vendor-' . now()->format('Ymd-His'));
    }

    public function hutangVendorExportPdf(Request $request): Response
    {
        $this->authorizeView();

        $filters = $this->scopedFilters($request, [
            'tanggal_dari', 'tanggal_sampai', 'vendor_ap_id', 'perusahaan_id', 'as_of_date',
        ]);
        $report = $this->service->getHutangVendor($filters);

        return $this->streamPdf('finance.ap-laporan.hutang-vendor-pdf', ['report' => $report], 'hutang-vendor-' . now()->format('Ymd-His'));
    }

    // ─── Aging Hutang ───────────────────────────────────────────────

    public function aging(Request $request): JsonResponse
    {
        $this->authorizeView();

        $request->validate([
            'as_of_date'    => ['nullable', 'date'],
            'vendor_ap_id'  => ['nullable', 'integer', 'exists:tb_vendor_ap,id'],
            'perusahaan_id' => ['nullable', 'integer', 'exists:tb_perusahaan,id'],
            'page'          => ['nullable', 'integer', 'min:1'],
            'per_page'      => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filters = $this->scopedFilters($request, ['as_of_date', 'vendor_ap_id', 'perusahaan_id']);
        $report  = $this->service->getAging($filters);

        if ($perPage = $request->integer('per_page')) {
            ['items' => $report['rows'], 'meta' => $report['meta']]
                = $this->paginateArray($report['rows'], $request->integer('page', 1), $perPage);
        }

        return $this->successResponse($report);
    }

    public function agingExportExcel(Request $request): BinaryFileResponse|JsonResponse
    {
        $this->authorizeView();

        if ($zipError = $this->ensureZipAvailable()) {
            return $zipError;
        }

        $filters = $this->scopedFilters($request, ['as_of_date', 'vendor_ap_id', 'perusahaan_id']);
        $report  = $this->service->getAging($filters);

        $spreadsheet = $this->exportService->buildAging($report);

        return $this->streamXlsx($spreadsheet, 'aging-hutang-' . now()->format('Ymd-His'));
    }

    public function agingExportPdf(Request $request): Response
    {
        $this->authorizeView();

        $filters = $this->scopedFilters($request, ['as_of_date', 'vendor_ap_id', 'perusahaan_id']);
        $report  = $this->service->getAging($filters);

        return $this->streamPdf('finance.ap-laporan.aging-pdf', ['report' => $report], 'aging-hutang-' . now()->format('Ymd-His'));
    }

    // ─── Histori Pembayaran ─────────────────────────────────────────

    public function historiPembayaran(Request $request): JsonResponse
    {
        $this->authorizeView();

        $request->validate([
            'tanggal_dari'      => ['nullable', 'date'],
            'tanggal_sampai'    => ['nullable', 'date', 'after_or_equal:tanggal_dari'],
            'vendor_ap_id'      => ['nullable', 'integer', 'exists:tb_vendor_ap,id'],
            'perusahaan_id'     => ['nullable', 'integer', 'exists:tb_perusahaan,id'],
            'metode_pembayaran' => ['nullable', 'in:TRANSFER,CASH,GIRO'],
            'kategori_voucher'  => ['nullable', 'in:BB,NBB'],
            'page'              => ['nullable', 'integer', 'min:1'],
            'per_page'          => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filters = $this->scopedFilters($request, [
            'tanggal_dari', 'tanggal_sampai', 'vendor_ap_id', 'perusahaan_id', 'metode_pembayaran', 'kategori_voucher',
        ]);

        $report = $this->service->getHistoriPembayaran($filters);

        if ($perPage = $request->integer('per_page')) {
            ['items' => $report['rows'], 'meta' => $report['meta']]
                = $this->paginateArray($report['rows'], $request->integer('page', 1), $perPage);
        }

        return $this->successResponse($report);
    }

    public function historiPembayaranExportExcel(Request $request): BinaryFileResponse|JsonResponse
    {
        $this->authorizeView();

        if ($zipError = $this->ensureZipAvailable()) {
            return $zipError;
        }

        $filters = $this->scopedFilters($request, [
            'tanggal_dari', 'tanggal_sampai', 'vendor_ap_id', 'perusahaan_id', 'metode_pembayaran', 'kategori_voucher',
        ]);

        $report = $this->service->getHistoriPembayaran($filters);

        $spreadsheet = $this->exportService->buildHistoriPembayaran($report);

        return $this->streamXlsx($spreadsheet, 'histori-pembayaran-ap-' . now()->format('Ymd-His'));
    }

    public function historiPembayaranExportPdf(Request $request): Response
    {
        $this->authorizeView();

        $filters = $this->scopedFilters($request, [
            'tanggal_dari', 'tanggal_sampai', 'vendor_ap_id', 'perusahaan_id', 'metode_pembayaran', 'kategori_voucher',
        ]);

        $report = $this->service->getHistoriPembayaran($filters);

        return $this->streamPdf(
            'finance.ap-laporan.histori-pembayaran-pdf',
            ['report' => $report, 'rows' => $report['rows']],
            'histori-pembayaran-ap-' . now()->format('Ymd-His')
        );
    }

    // ─── Helpers ────────────────────────────────────────────────────

    private function scopedFilters(Request $request, array $keys): array
    {
        $filters = $request->only($keys);
        ApFilterScope::apply($filters, $request->user());

        return $filters;
    }

    private function authorizeView(): void
    {
        abort_if(!RoleHelper::canViewTagihanAp(auth()->user()), 403, 'Tidak memiliki akses ke laporan AP');
    }

    private function ensureZipAvailable(): ?JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        return null;
    }

    private function streamXlsx(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, string $filenameBase): BinaryFileResponse
    {
        $temp = tempnam(sys_get_temp_dir(), 'apl_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, "{$filenameBase}.xlsx", [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    private function streamPdf(string $view, array $data, string $filenameBase): Response
    {
        $data['printed_at'] = now()->format('d-m-Y H:i');

        return Pdf::loadView($view, $data)
            ->setPaper('a4', 'landscape')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => false,
                'defaultFont'          => 'Arial',
                'dpi'                  => 96,
            ])
            ->stream("{$filenameBase}.pdf");
    }
}
