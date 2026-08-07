<?php

namespace App\Domain\Finance\MutasiPiutang\Controllers;

use App\Domain\Finance\MutasiPiutang\Services\MutasiPiutangService;
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

class MutasiPiutangController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly MutasiPiutangService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'periode_awal'  => ['nullable', 'date'],
            'periode_akhir' => ['nullable', 'date', 'after_or_equal:periode_awal'],
            'klien_ar_id'   => ['nullable', 'integer', 'exists:tb_klien_ar,id'],
            'segment'       => ['nullable', 'in:B2B,B2C,ALL'],
            'page'          => ['nullable', 'integer', 'min:1'],
            'per_page'      => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filters = $request->only(['periode_awal', 'periode_akhir', 'klien_ar_id', 'segment']);
        ArFilterScope::apply($filters, $request->user());

        $report = $this->service->getReport($filters);

        if ($perPage = $request->integer('per_page')) {
            ['items' => $report['rows'], 'meta' => $report['meta']]
                = $this->paginateArray($report['rows'], $request->integer('page', 1), $perPage);
        }

        return $this->successResponse($report);
    }

    private function exportFilterRules(): array
    {
        return [
            'periode_awal'  => ['nullable', 'date'],
            'periode_akhir' => ['nullable', 'date', 'after_or_equal:periode_awal'],
            'klien_ar_id'   => ['nullable', 'integer', 'exists:tb_klien_ar,id'],
            'segment'       => ['nullable', 'in:B2B,B2C,ALL'],
        ];
    }

    /**
     * Jumlah baris detail mutasi yang akan dihasilkan export untuk filter yang sama —
     * dipakai FE untuk peringatan real-time XLSX vs CSV di modal Export.
     */
    public function exportRowCount(Request $request): JsonResponse
    {
        $request->validate($this->exportFilterRules());

        $filters = $request->only(['periode_awal', 'periode_akhir', 'klien_ar_id', 'segment']);
        ArFilterScope::apply($filters, $request->user());

        $report   = $this->service->getReport($filters);
        $rowCount = array_sum(array_map(fn($row) => count($row['details'] ?? []), $report['rows']));

        return $this->successResponse(['row_count' => $rowCount]);
    }

    public function exportExcel(Request $request): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $request->validate($this->exportFilterRules());

        $filters = $request->only(['periode_awal', 'periode_akhir', 'klien_ar_id', 'segment']);
        ArFilterScope::apply($filters, $request->user());

        $report = $this->service->getReport($filters);
        $format = strtolower((string) $request->query('format', 'xlsx'));

        return $format === 'csv' ? $this->streamCsvExport($report) : $this->streamXlsxExport($report);
    }

    /**
     * Export CSV — blok ringkasan per klien (kiri) & blok detail mutasi (kanan, dipisah 1
     * kolom kosong) ditulis berdampingan pada baris yang sama, meniru tampilan 2-sheet versi
     * XLSX (lihat streamXlsxExport()). Pola sama seperti AgingReportController::streamCsvExport().
     */
    private function streamCsvExport(array $report): StreamedResponse
    {
        $summaryHeaders = ['No', 'Kode Klien', 'Nama Klien', 'Entitas', 'Saldo Awal', 'Invoice Masuk', 'Pembayaran', 'Saldo Akhir'];
        $detailHeaders  = ['No', 'Kode Klien', 'Nama Klien', 'Tanggal', 'Tipe', 'Dokumen', 'Invoice', 'Debit', 'Kredit', 'Saldo', 'Keterangan'];

        $summaryRows = [];
        foreach ($report['rows'] as $i => $row) {
            $summaryRows[] = [
                $i + 1,
                $row['kode_klien'] ?? '',
                $row['nama_klien'] ?? '',
                $row['perusahaan'] ?? '',
                $row['saldo_awal'] ?? 0,
                $row['invoice_masuk'] ?? 0,
                $row['pembayaran'] ?? 0,
                $row['saldo_akhir'] ?? 0,
            ];
        }

        $detailRows = [];
        $no         = 1;
        foreach ($report['rows'] as $row) {
            foreach (($row['details'] ?? []) as $detail) {
                $detailRows[] = [
                    $no++,
                    $row['kode_klien'] ?? '',
                    $row['nama_klien'] ?? '',
                    $detail['tanggal'] ?? '',
                    $detail['label'] ?? $detail['tipe'] ?? '',
                    $detail['no_dokumen'] ?? '',
                    $detail['no_invoice'] ?? '',
                    $detail['debit'] ?? 0,
                    $detail['kredit'] ?? 0,
                    $detail['saldo'] ?? 0,
                    $detail['keterangan'] ?? '',
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

            // Kasus klien tanpa mutasi pada periode ini: baris ringkasannya belum sempat
            // ditulis di loop atas — tulis sisanya dengan blok detail kosong.
            while ($rowIndex < $summaryCount) {
                fputcsv($handle, array_merge($summaryRows[$rowIndex], [''], $detailBlank), ';');
                $rowIndex++;
            }

            fclose($handle);
        }, 'mutasi-piutang-' . now()->format('Ymd') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Export XLSX asli (PhpSpreadsheet), 2 sheet: "Mutasi Piutang" & "Detail Mutasi".
     */
    private function streamXlsxExport(array $report): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Mutasi Piutang');

        $lastCol = 'H';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'MUTASI PIUTANG');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4527A0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $periodeText = 'Periode: ' . $report['periode_awal'] . ' s/d ' . $report['periode_akhir']
            . '   |   Diekspor: ' . now()->format('d-m-Y H:i');
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', $periodeText);
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF455A64']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEDE7F6']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(18);
        $sheet->getRowDimension(3)->setRowHeight(6);

        $cols = [
            'A' => ['No',           5],
            'B' => ['Kode Klien',  16],
            'C' => ['Nama Klien',  30],
            'D' => ['Entitas',     14],
            'E' => ['Saldo Awal',  20],
            'F' => ['Invoice Masuk', 20],
            'G' => ['Pembayaran',  20],
            'H' => ['Saldo Akhir', 20],
        ];

        foreach ($cols as $col => [$label, $width]) {
            $sheet->setCellValue("{$col}4", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF512DA8']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF4527A0']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(22);

        $rowNum = 5;
        foreach ($report['rows'] as $i => $row) {
            $bg = $rowNum % 2 === 0 ? 'FFEDE7F6' : 'FFFFFFFF';

            $sheet->getCell("A{$rowNum}")->setValueExplicit($i + 1, DataType::TYPE_NUMERIC);
            $sheet->getCell("B{$rowNum}")->setValueExplicit($row['kode_klien'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("C{$rowNum}")->setValueExplicit($row['nama_klien'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("D{$rowNum}")->setValueExplicit($row['perusahaan'] ?? '', DataType::TYPE_STRING);

            foreach (['saldo_awal' => 'E', 'invoice_masuk' => 'F', 'pembayaran' => 'G', 'saldo_akhir' => 'H'] as $field => $col) {
                $val = (float) ($row[$field] ?? 0);
                $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val, DataType::TYPE_NUMERIC);
                $sheet->getStyle("{$col}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
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
        foreach (['saldo_awal' => 'E', 'invoice_masuk' => 'F', 'pembayaran' => 'G', 'saldo_akhir' => 'H'] as $field => $col) {
            $sheet->getCell("{$col}{$rowNum}")->setValueExplicit((float) ($summary[$field] ?? 0), DataType::TYPE_NUMERIC);
            $sheet->getStyle("{$col}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
        }
        $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
            'font'    => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF4527A0']],
            'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEDE7F6']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF512DA8']]],
        ]);
        $sheet->getRowDimension($rowNum)->setRowHeight(20);

        $sheet->freezePane('A5');

        $detailSheet = $spreadsheet->createSheet();
        $detailSheet->setTitle('Detail Mutasi');
        $detailCols = [
            'A' => ['No',          5],
            'B' => ['Kode Klien', 16],
            'C' => ['Nama Klien', 30],
            'D' => ['Tanggal',    14],
            'E' => ['Tipe',       14],
            'F' => ['Dokumen',    24],
            'G' => ['Invoice',    22],
            'H' => ['Debit',      18],
            'I' => ['Kredit',     18],
            'J' => ['Saldo',      18],
            'K' => ['Keterangan', 32],
        ];

        foreach ($detailCols as $col => [$label, $width]) {
            $detailSheet->setCellValue("{$col}1", $label);
            $detailSheet->getColumnDimension($col)->setWidth($width);
        }

        $detailSheet->getStyle('A1:K1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF512DA8']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF4527A0']]],
        ]);

        $detailRow = 2;
        $detailNo  = 1;
        foreach ($report['rows'] as $row) {
            foreach (($row['details'] ?? []) as $detail) {
                $detailSheet->getCell("A{$detailRow}")->setValueExplicit($detailNo++, DataType::TYPE_NUMERIC);
                $detailSheet->getCell("B{$detailRow}")->setValueExplicit($row['kode_klien'] ?? '', DataType::TYPE_STRING);
                $detailSheet->getCell("C{$detailRow}")->setValueExplicit($row['nama_klien'] ?? '', DataType::TYPE_STRING);
                $detailSheet->getCell("D{$detailRow}")->setValueExplicit($detail['tanggal'] ?? '', DataType::TYPE_STRING);
                $detailSheet->getCell("E{$detailRow}")->setValueExplicit($detail['label'] ?? $detail['tipe'] ?? '', DataType::TYPE_STRING);
                $detailSheet->getCell("F{$detailRow}")->setValueExplicit($detail['no_dokumen'] ?? '', DataType::TYPE_STRING);
                $detailSheet->getCell("G{$detailRow}")->setValueExplicit($detail['no_invoice'] ?? '', DataType::TYPE_STRING);

                foreach (['debit' => 'H', 'kredit' => 'I', 'saldo' => 'J'] as $field => $col) {
                    $detailSheet->getCell("{$col}{$detailRow}")->setValueExplicit((float) ($detail[$field] ?? 0), DataType::TYPE_NUMERIC);
                    $detailSheet->getStyle("{$col}{$detailRow}")->getNumberFormat()->setFormatCode('#,##0');
                }

                $detailSheet->getCell("K{$detailRow}")->setValueExplicit($detail['keterangan'] ?? '', DataType::TYPE_STRING);
                $detailRow++;
            }
        }

        $detailSheet->freezePane('A2');
        $spreadsheet->setActiveSheetIndex(0);

        $temp = tempnam(sys_get_temp_dir(), 'mp_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'mutasi-piutang-' . now()->format('Ymd') . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }
}
