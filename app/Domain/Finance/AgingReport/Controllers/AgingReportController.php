<?php

namespace App\Domain\Finance\AgingReport\Controllers;

use App\Domain\Finance\AgingReport\Services\AgingReportService;
use App\Http\Controllers\Controller;
use App\Support\Helpers\ArFilterScope;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgingReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AgingReportService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'as_of_date'     => ['nullable', 'date'],
            'klien_ar_id'    => ['nullable', 'integer', 'exists:tb_klien_ar,id'],
            'perusahaan_id'  => ['nullable', 'integer', 'exists:tb_perusahaan,id'],
            'karyawan_ar_id' => ['nullable', 'integer', 'exists:tb_karyawan,id'],
            'segment'        => ['nullable', 'in:B2B,B2C,ALL'],
            'bucket_filter'  => ['nullable', 'in:current,hari_1_30,hari_31_60,hari_61_90,hari_91_plus'],
            'page'           => ['nullable', 'integer', 'min:1'],
            'per_page'       => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filters = $request->only(['as_of_date', 'klien_ar_id', 'perusahaan_id', 'karyawan_ar_id', 'segment']);
        ArFilterScope::apply($filters, $request->user());

        $report = $this->service->getReport($filters);

        // Quick-filter bucket (klik kartu summary) diterapkan di sini, bukan di
        // service, karena summary tetap harus dihitung dari seluruh baris —
        // hanya daftar klien yang ditampilkan yang dipersempit.
        if ($bucket = $request->string('bucket_filter')->toString()) {
            $report['rows'] = array_values(array_filter(
                $report['rows'],
                fn($row) => ($row[$bucket] ?? 0) > 0
            ));
        }

        if ($perPage = $request->integer('per_page')) {
            ['items' => $report['rows'], 'meta' => $report['meta']]
                = $this->paginateArray($report['rows'], $request->integer('page', 1), $perPage);
        }

        return $this->successResponse($report);
    }

    private function exportFilterRules(): array
    {
        return [
            'as_of_date'     => ['nullable', 'date'],
            'klien_ar_id'    => ['nullable', 'integer', 'exists:tb_klien_ar,id'],
            'perusahaan_id'  => ['nullable', 'integer', 'exists:tb_perusahaan,id'],
            'karyawan_ar_id' => ['nullable', 'integer', 'exists:tb_karyawan,id'],
            'segment'        => ['nullable', 'in:B2B,B2C,ALL'],
        ];
    }

    /**
     * Jumlah baris detail invoice yang akan dihasilkan export untuk filter yang sama —
     * dipakai FE untuk peringatan real-time XLSX vs CSV di modal Export.
     */
    public function exportRowCount(Request $request): JsonResponse
    {
        $request->validate($this->exportFilterRules());

        $filters = $request->only(['as_of_date', 'klien_ar_id', 'perusahaan_id', 'karyawan_ar_id', 'segment']);
        ArFilterScope::apply($filters, $request->user());

        $report   = $this->service->getReport($filters);
        $rowCount = array_sum(array_map(fn($row) => count($row['details'] ?? []), $report['rows']));

        return $this->successResponse(['row_count' => $rowCount]);
    }

    public function exportExcel(Request $request): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $request->validate($this->exportFilterRules());

        $filters = $request->only(['as_of_date', 'klien_ar_id', 'perusahaan_id', 'karyawan_ar_id', 'segment']);
        ArFilterScope::apply($filters, $request->user());

        $report = $this->service->getReport($filters);
        $format = strtolower((string) $request->query('format', 'xlsx'));

        return $format === 'csv' ? $this->streamCsvExport($report) : $this->streamXlsxExport($report);
    }

    /**
     * Export CSV — blok ringkasan per klien (kiri) & blok detail invoice (kanan, dipisah 1
     * kolom kosong) ditulis berdampingan pada baris yang sama, meniru tampilan 2-sheet versi
     * XLSX (lihat streamXlsxExport()). Pola sama seperti InvoiceController::streamCsvExport().
     */
    private function streamCsvExport(array $report): StreamedResponse
    {
        $summaryHeaders = ['No', 'Kode Klien', 'Nama Klien', 'Entitas', 'Belum JT', '1–30 Hari', '31–60 Hari', '61–90 Hari', '>90 Hari', 'Total'];
        $detailHeaders  = ['No', 'Kode Klien', 'Nama Klien', 'No Invoice', 'Tgl Invoice', 'Jatuh Tempo', 'Umur (hari)', 'Hari Terlambat', 'Bucket', 'Total Tagihan', 'Total Bayar', 'Sisa Tagihan', 'Status', 'PIC AR', 'Entitas'];

        $bucketLabels = [
            'current'      => 'Belum JT',
            'hari_1_30'    => '1–30 Hari',
            'hari_31_60'   => '31–60 Hari',
            'hari_61_90'   => '61–90 Hari',
            'hari_91_plus' => '>90 Hari',
        ];

        $summaryRows = [];
        foreach ($report['rows'] as $i => $row) {
            $summaryRows[] = [
                $i + 1,
                $row['kode_klien'] ?? '',
                $row['nama_klien'] ?? '',
                $row['perusahaan'] ?? '',
                $row['current'] ?? 0,
                $row['hari_1_30'] ?? 0,
                $row['hari_31_60'] ?? 0,
                $row['hari_61_90'] ?? 0,
                $row['hari_91_plus'] ?? 0,
                $row['total'] ?? 0,
            ];
        }

        $detailRows = [];
        $no         = 1;
        foreach ($report['rows'] as $klienRow) {
            foreach (($klienRow['details'] ?? []) as $d) {
                $detailRows[] = [
                    $no++,
                    $klienRow['kode_klien'] ?? '',
                    $klienRow['nama_klien'] ?? '',
                    $d['no_invoice'] ?? '',
                    $d['tanggal_invoice'] ?? '',
                    $d['tanggal_jatuh_tempo'] ?? '',
                    $d['umur_invoice'] ?? '',
                    $d['hari_terlambat'] ?? 0,
                    $bucketLabels[$d['bucket'] ?? ''] ?? ($d['bucket'] ?? ''),
                    $d['total_tagihan'] ?? 0,
                    $d['total_pembayaran'] ?? 0,
                    $d['sisa_tagihan'] ?? 0,
                    $d['status'] ?? '',
                    $d['pic_ar'] ?? '',
                    $d['perusahaan'] ?? '',
                ];
            }
        }

        $summaryCount = count($summaryRows);
        $summaryBlank = array_fill(0, count($summaryHeaders), '');
        $detailBlank  = array_fill(0, count($detailHeaders), '');
        $header       = array_merge($summaryHeaders, [''], $detailHeaders);

        return response()->streamDownload(function () use ($detailRows, $summaryRows, $summaryCount, $summaryBlank, $detailBlank, $header) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
            fputcsv($handle, $header, ';');

            $rowIndex = 0;
            foreach ($detailRows as $detail) {
                $left = $rowIndex < $summaryCount ? $summaryRows[$rowIndex] : $summaryBlank;
                fputcsv($handle, array_merge($left, [''], $detail), ';');
                $rowIndex++;
            }

            // Kasus klien tanpa invoice tertunggak (jarang, tapi mungkin): baris ringkasannya
            // belum sempat ditulis di loop atas — tulis sisanya dengan blok detail kosong.
            while ($rowIndex < $summaryCount) {
                fputcsv($handle, array_merge($summaryRows[$rowIndex], [''], $detailBlank), ';');
                $rowIndex++;
            }

            fclose($handle);
        }, 'aging-report-' . $report['as_of_date'] . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Export XLSX asli (PhpSpreadsheet), 2 sheet: "Summary per Klien" & "Detail Invoice".
     */
    private function streamXlsxExport(array $report): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Summary per Klien');

        $lastCol = 'J';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'LAPORAN AGING PIUTANG');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFB71C1C']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', 'Per Tanggal: ' . $report['as_of_date'] . '   |   Diekspor: ' . now()->format('d-m-Y H:i'));
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF455A64']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFEBEE']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(18);
        $sheet->getRowDimension(3)->setRowHeight(6);

        $cols = [
            'A' => ['No',                  5],
            'B' => ['Kode Klien',         16],
            'C' => ['Nama Klien',         30],
            'D' => ['Entitas',            14],
            'E' => ['Belum JT',           18],
            'F' => ['1–30 Hari',          18],
            'G' => ['31–60 Hari',         18],
            'H' => ['61–90 Hari',         18],
            'I' => ['>90 Hari',           18],
            'J' => ['Total',              20],
        ];

        foreach ($cols as $col => [$label, $width]) {
            $sheet->setCellValue("{$col}4", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFC62828']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFB71C1C']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(22);

        $rowNum = 5;
        foreach ($report['rows'] as $i => $row) {
            $bg = $rowNum % 2 === 0 ? 'FFFFEBEE' : 'FFFFFFFF';

            $sheet->getCell("A{$rowNum}")->setValueExplicit($i + 1, DataType::TYPE_NUMERIC);
            $sheet->getCell("B{$rowNum}")->setValueExplicit($row['kode_klien'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("C{$rowNum}")->setValueExplicit($row['nama_klien'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("D{$rowNum}")->setValueExplicit($row['perusahaan'] ?? '', DataType::TYPE_STRING);

            foreach (['current' => 'E', 'hari_1_30' => 'F', 'hari_31_60' => 'G', 'hari_61_90' => 'H', 'hari_91_plus' => 'I', 'total' => 'J'] as $field => $col) {
                $val = (float) ($row[$field] ?? 0);
                $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val ?: '', $val ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                if ($val) $sheet->getStyle("{$col}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
            }

            $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($rowNum)->setRowHeight(18);
            $rowNum++;
        }

        $summary = $report['summary'];
        $sheet->mergeCells("A{$rowNum}:D{$rowNum}");
        $sheet->setCellValue("A{$rowNum}", 'TOTAL');
        foreach (['current' => 'E', 'hari_1_30' => 'F', 'hari_31_60' => 'G', 'hari_61_90' => 'H', 'hari_91_plus' => 'I', 'total' => 'J'] as $field => $col) {
            $sheet->getCell("{$col}{$rowNum}")->setValueExplicit((float) ($summary[$field] ?? 0), DataType::TYPE_NUMERIC);
            $sheet->getStyle("{$col}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
        }
        $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
            'font'    => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFB71C1C']],
            'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFEBEE']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFC62828']]],
        ]);
        $sheet->getRowDimension($rowNum)->setRowHeight(20);

        $sheet->freezePane('A5');

        // ─── Sheet 2: Detail Invoice ────────────────────────────────────────
        $this->buildDetailSheet($spreadsheet, $report);
        $spreadsheet->setActiveSheetIndex(0);

        $temp = tempnam(sys_get_temp_dir(), 'ar_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'aging-report-' . $report['as_of_date'] . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    private function buildDetailSheet(Spreadsheet $spreadsheet, array $report): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Detail Invoice');

        $lastCol = 'O';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'DETAIL INVOICE PIUTANG');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFB71C1C']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', 'Per Tanggal: ' . $report['as_of_date'] . '   |   Diekspor: ' . now()->format('d-m-Y H:i'));
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF455A64']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFEBEE']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(18);
        $sheet->getRowDimension(3)->setRowHeight(6);

        $cols = [
            'A' => ['No',              5],
            'B' => ['Kode Klien',     14],
            'C' => ['Nama Klien',     26],
            'D' => ['No Invoice',     18],
            'E' => ['Tgl Invoice',    13],
            'F' => ['Jatuh Tempo',    13],
            'G' => ['Umur (hari)',    11],
            'H' => ['Hari Terlambat', 13],
            'I' => ['Bucket',         14],
            'J' => ['Total Tagihan',  16],
            'K' => ['Total Bayar',    16],
            'L' => ['Sisa Tagihan',   16],
            'M' => ['Status',         16],
            'N' => ['PIC AR',         18],
            'O' => ['Entitas',        12],
        ];

        foreach ($cols as $col => [$label, $width]) {
            $sheet->setCellValue("{$col}4", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFC62828']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFB71C1C']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(22);

        $bucketLabels = [
            'current'      => 'Belum JT',
            'hari_1_30'    => '1–30 Hari',
            'hari_31_60'   => '31–60 Hari',
            'hari_61_90'   => '61–90 Hari',
            'hari_91_plus' => '>90 Hari',
        ];

        $rowNum = 5;
        $no     = 1;
        foreach ($report['rows'] as $klienRow) {
            foreach (($klienRow['details'] ?? []) as $d) {
                $bg = $rowNum % 2 === 0 ? 'FFFFEBEE' : 'FFFFFFFF';

                $sheet->getCell("A{$rowNum}")->setValueExplicit($no, DataType::TYPE_NUMERIC);
                $sheet->getCell("B{$rowNum}")->setValueExplicit($klienRow['kode_klien'] ?? '', DataType::TYPE_STRING);
                $sheet->getCell("C{$rowNum}")->setValueExplicit($klienRow['nama_klien'] ?? '', DataType::TYPE_STRING);
                $sheet->getCell("D{$rowNum}")->setValueExplicit($d['no_invoice'] ?? '', DataType::TYPE_STRING);
                $sheet->getCell("E{$rowNum}")->setValueExplicit($d['tanggal_invoice'] ?? '', DataType::TYPE_STRING);
                $sheet->getCell("F{$rowNum}")->setValueExplicit($d['tanggal_jatuh_tempo'] ?? '', DataType::TYPE_STRING);

                $umur = $d['umur_invoice'];
                $sheet->getCell("G{$rowNum}")->setValueExplicit($umur === null ? '' : (int) $umur, $umur === null ? DataType::TYPE_STRING : DataType::TYPE_NUMERIC);
                $sheet->getCell("H{$rowNum}")->setValueExplicit((int) ($d['hari_terlambat'] ?? 0), DataType::TYPE_NUMERIC);
                $sheet->getCell("I{$rowNum}")->setValueExplicit($bucketLabels[$d['bucket'] ?? ''] ?? ($d['bucket'] ?? ''), DataType::TYPE_STRING);

                foreach (['total_tagihan' => 'J', 'total_pembayaran' => 'K', 'sisa_tagihan' => 'L'] as $field => $col) {
                    $val = (float) ($d[$field] ?? 0);
                    $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val ?: '', $val ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                    if ($val) $sheet->getStyle("{$col}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
                }

                $sheet->getCell("M{$rowNum}")->setValueExplicit($d['status'] ?? '', DataType::TYPE_STRING);
                $sheet->getCell("N{$rowNum}")->setValueExplicit($d['pic_ar'] ?? '', DataType::TYPE_STRING);
                $sheet->getCell("O{$rowNum}")->setValueExplicit($d['perusahaan'] ?? '', DataType::TYPE_STRING);

                $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension($rowNum)->setRowHeight(18);
                $rowNum++;
                $no++;
            }
        }

        $sheet->freezePane('A5');
    }
}
