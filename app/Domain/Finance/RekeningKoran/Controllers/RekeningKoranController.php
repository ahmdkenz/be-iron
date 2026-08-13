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
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    private const EXPORT_FILTER_KEYS = ['pic_ar_id', 'periode_awal', 'periode_akhir', 'bank_type', 'dk', 'status_posting_1', 'status_posting_2'];

    /**
     * Jumlah baris yang akan dihasilkan export untuk filter yang sama — dipakai
     * FE untuk peringatan real-time XLSX vs CSV di modal Export.
     */
    public function exportRowCount(Request $request): JsonResponse
    {
        $this->validateFilters($request);

        $rowCount = $this->service->countAllRows($request->only(self::EXPORT_FILTER_KEYS));

        return $this->successResponse(['row_count' => $rowCount]);
    }

    /**
     * Export resmi Rekening Koran. XLSX memakai workbook service yang sama dengan
     * Export Data supaya kolom & format sheet-nya identik, baik diminta dari
     * halaman laporan ini maupun dari pusat Export Data. CSV di-stream langsung
     * dari data source yang sama untuk dataset besar.
     */
    public function exportExcel(Request $request): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $this->validateFilters($request);

        $format = strtolower((string) $request->query('format', 'xlsx'));

        if ($format === 'csv') {
            return $this->streamCsvExport($request->only(self::EXPORT_FILTER_KEYS));
        }

        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $spreadsheet = $this->exportService->build(
            ['rekening_koran'],
            $request->only(self::EXPORT_FILTER_KEYS),
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

    /**
     * Export CSV — kolom persis sama seperti sheet Rekening Koran di
     * ExportDataWorkbookService::rekeningKoranSection(), supaya CSV & XLSX tidak
     * pernah berbeda isi.
     */
    private function streamCsvExport(array $filters): StreamedResponse
    {
        $rows = $this->service->getAllRows($filters);

        $headers = ['No', 'TRXID', 'Tanggal', 'Waktu', 'Bank', 'D/K', 'Mutasi', 'Saldo', 'Deskripsi', 'Status Posting 1', 'No Dokumen AR', 'Selisih', 'Status Posting 2', 'PIC AR', 'Posted By', 'Posted At'];

        return response()->streamDownload(function () use ($rows, $headers) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
            fputcsv($handle, $headers, ';');

            foreach ($rows as $i => $row) {
                fputcsv($handle, [
                    $i + 1,
                    $row['no_referensi'] ?? '',
                    $row['tanggal'] ?? '',
                    $row['waktu_transaksi'] ?? '',
                    $row['bank_type'] ?? '',
                    $row['dk'] ?? '',
                    $row['mutasi'] ?? 0,
                    $row['saldo'] ?? 0,
                    $row['keterangan'] ?? '',
                    $row['status_posting_1'] ?? '',
                    $row['no_dokumen_ar'] ?? '',
                    $row['selisih'] ?? 0,
                    $row['status_posting_2'] ?? '',
                    $row['pic_ar'] ?? '',
                    $row['posted_by'] ?? '',
                    $row['posted_at'] ?? '',
                ], ';');
            }

            fclose($handle);
        }, 'rekening-koran-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
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
