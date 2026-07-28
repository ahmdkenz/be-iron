<?php

namespace App\Domain\Finance\ExportData\Controllers;

use App\Domain\Finance\ExportData\Requests\ExportDataExcelRequest;
use App\Domain\Finance\ExportData\Services\ExportDataWorkbookService;
use App\Http\Controllers\Controller;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportDataController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ExportDataWorkbookService $service) {}

    /**
     * Satu request → satu file .xlsx. Satu laporan terpilih menghasilkan
     * workbook 1 sheet, beberapa laporan menghasilkan 1 sheet per laporan
     * dengan urutan mengikuti urutan pilihan user.
     */
    public function exportExcel(ExportDataExcelRequest $request): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        // Satu request bisa merangkum 9 laporan sekaligus (puluhan ribu baris),
        // jauh lebih berat dari export laporan satuan — batas default PHP
        // (sering 30-60 detik) terlalu ketat untuk kasus itu.
        @set_time_limit(300);

        $spreadsheet = $this->service->build(
            $request->reportKeys(),
            $request->reportFilters(),
            $request->user(),
        );

        $temp = tempnam(sys_get_temp_dir(), 'exp_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'export-data-finance-' . now()->format('Ymd-His') . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }
}
