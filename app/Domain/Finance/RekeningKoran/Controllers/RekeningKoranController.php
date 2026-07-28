<?php

namespace App\Domain\Finance\RekeningKoran\Controllers;

use App\Domain\Finance\ExportData\Services\ExportDataWorkbookService;
use App\Domain\Finance\RekeningKoran\Services\RekeningKoranUmumService;
use App\Http\Controllers\Controller;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RekeningKoranController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly RekeningKoranUmumService $service,
        private readonly ExportDataWorkbookService $exportService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->validateFilters($request, withPagination: true);

        $report = $this->service->getReport(
            $request->only(['pic_ar_id', 'periode_awal', 'periode_akhir', 'bank_type', 'dk', 'status_posting_1', 'status_posting_2']),
            (int) $request->input('per_page', 25),
            (int) $request->input('page', 1),
        );

        return $this->successResponse($report);
    }

    /**
     * Export resmi Rekening Koran. Memakai workbook service yang sama dengan
     * Export Data supaya kolom & format sheet-nya identik, baik diminta dari
     * halaman laporan ini maupun dari pusat Export Data.
     */
    public function exportExcel(Request $request): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $this->validateFilters($request);

        $spreadsheet = $this->exportService->build(
            ['rekening_koran'],
            $request->only(['pic_ar_id', 'periode_awal', 'periode_akhir', 'bank_type', 'dk', 'status_posting_1', 'status_posting_2']),
            $request->user(),
        );

        $temp = tempnam(sys_get_temp_dir(), 'rk_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'rekening-koran-' . now()->format('Ymd-His') . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    public function picArList(): JsonResponse
    {
        return $this->successResponse($this->service->getPicArList());
    }

    private function validateFilters(Request $request, bool $withPagination = false): void
    {
        $request->validate(array_merge([
            'pic_ar_id'        => ['nullable', 'integer', 'exists:tb_users,id'],
            'periode_awal'     => ['nullable', 'date'],
            'periode_akhir'    => ['nullable', 'date', 'after_or_equal:periode_awal'],
            'bank_type'        => ['nullable', 'in:BCA,MANDIRI,CIMB,BSI'],
            'dk'               => ['nullable', 'in:K,D'],
            'status_posting_1' => ['nullable', 'in:MATCHED,UNMATCHED,DIABAIKAN'],
            'status_posting_2' => ['nullable', 'in:POSTED,PENDING'],
        ], $withPagination ? [
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
        ] : []));
    }
}
