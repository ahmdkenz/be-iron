<?php

namespace App\Domain\Finance\RekeningKoran\Controllers;

use App\Domain\Finance\RekeningKoran\Services\RekeningKoranService;
use App\Http\Controllers\Controller;
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

class RekeningKoranController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RekeningKoranService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'klien_ar_id'   => ['required', 'integer', 'exists:tb_klien_ar,id'],
            'periode_awal'  => ['nullable', 'date'],
            'periode_akhir' => ['nullable', 'date', 'after_or_equal:periode_awal'],
        ]);

        $report = $this->service->getReport(
            $request->only(['klien_ar_id', 'periode_awal', 'periode_akhir'])
        );

        return $this->successResponse($report);
    }

    public function exportExcel(Request $request): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $request->validate([
            'klien_ar_id'   => ['required', 'integer', 'exists:tb_klien_ar,id'],
            'periode_awal'  => ['nullable', 'date'],
            'periode_akhir' => ['nullable', 'date', 'after_or_equal:periode_awal'],
        ]);

        $report = $this->service->getReport(
            $request->only(['klien_ar_id', 'periode_awal', 'periode_akhir'])
        );

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rekening Koran');

        $lastCol = 'G';

        // Baris 1: Judul
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'REKENING KORAN');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0D47A1']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        // Baris 2: Info klien
        $klienInfo = $report['klien']['nama_klien'] . ' (' . $report['klien']['kode_klien'] . ')';
        if ($report['klien']['perusahaan']) {
            $klienInfo .= '  |  Entitas: ' . $report['klien']['perusahaan'];
        }
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', $klienInfo);
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF1565C0']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE3F2FD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(22);

        // Baris 3: Periode
        $periodeInfo = 'Periode: ' . $report['periode_awal'] . ' s/d ' . $report['periode_akhir']
            . '   |   Diekspor: ' . now()->format('d-m-Y H:i');
        $sheet->mergeCells("A3:{$lastCol}3");
        $sheet->setCellValue('A3', $periodeInfo);
        $sheet->getStyle('A3')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF455A64']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE3F2FD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(3)->setRowHeight(18);
        $sheet->getRowDimension(4)->setRowHeight(6);

        // Header kolom
        $cols = [
            'A' => ['No',          6],
            'B' => ['Tanggal',    14],
            'C' => ['No. Dokumen', 24],
            'D' => ['Keterangan',  36],
            'E' => ['Debit',       18],
            'F' => ['Kredit',      18],
            'G' => ['Saldo',       18],
        ];

        foreach ($cols as $col => [$label, $width]) {
            $sheet->setCellValue("{$col}5", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A5:{$lastCol}5")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF0D47A1']]],
        ]);
        $sheet->getRowDimension(5)->setRowHeight(22);

        // Baris saldo awal
        $sheet->mergeCells("A6:D6");
        $sheet->setCellValue('A6', 'SALDO AWAL');
        $sheet->getCell('G6')->setValueExplicit((float) $report['saldo_awal'], DataType::TYPE_NUMERIC);
        $sheet->getStyle('G6')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('A6:G6')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF1A237E']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8EAF6']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
        ]);
        $sheet->getRowDimension(6)->setRowHeight(18);

        // Baris data
        $rowNum = 7;
        foreach ($report['rows'] as $i => $row) {
            $bg = $rowNum % 2 === 0 ? 'FFE3F2FD' : 'FFFFFFFF';

            $sheet->getCell("A{$rowNum}")->setValueExplicit($i + 1, DataType::TYPE_NUMERIC);
            $sheet->getCell("B{$rowNum}")->setValueExplicit($row['tanggal'], DataType::TYPE_STRING);
            $sheet->getCell("C{$rowNum}")->setValueExplicit($row['no_dokumen'], DataType::TYPE_STRING);
            $sheet->getCell("D{$rowNum}")->setValueExplicit($row['keterangan'], DataType::TYPE_STRING);
            $sheet->getCell("E{$rowNum}")->setValueExplicit($row['debit'] ?: '', $row['debit'] ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            $sheet->getCell("F{$rowNum}")->setValueExplicit($row['kredit'] ?: '', $row['kredit'] ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            $sheet->getCell("G{$rowNum}")->setValueExplicit((float) $row['saldo'], DataType::TYPE_NUMERIC);

            foreach (['E', 'F', 'G'] as $c) {
                $sheet->getStyle("{$c}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
            }

            $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);

            if ($row['debit'] > 0) {
                $sheet->getStyle("E{$rowNum}")->getFont()->setColor(
                    new \PhpOffice\PhpSpreadsheet\Style\Color('FFE65100')
                );
            }
            if ($row['kredit'] > 0) {
                $sheet->getStyle("F{$rowNum}")->getFont()->setColor(
                    new \PhpOffice\PhpSpreadsheet\Style\Color('FF2E7D32')
                );
            }

            $sheet->getRowDimension($rowNum)->setRowHeight(18);
            $rowNum++;
        }

        // Baris total + saldo akhir
        $sheet->mergeCells("A{$rowNum}:D{$rowNum}");
        $sheet->setCellValue("A{$rowNum}", 'TOTAL / SALDO AKHIR');
        $sheet->getCell("E{$rowNum}")->setValueExplicit((float) $report['total_debit'], DataType::TYPE_NUMERIC);
        $sheet->getCell("F{$rowNum}")->setValueExplicit((float) $report['total_kredit'], DataType::TYPE_NUMERIC);
        $sheet->getCell("G{$rowNum}")->setValueExplicit((float) $report['saldo_akhir'], DataType::TYPE_NUMERIC);
        foreach (['E', 'F', 'G'] as $c) {
            $sheet->getStyle("{$c}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
        }
        $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF1A237E']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8EAF6']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF0D47A1']],
            ],
        ]);
        $sheet->getRowDimension($rowNum)->setRowHeight(20);

        if ($rowNum > 7) {
            $sheet->getStyle("A6:{$lastCol}{$rowNum}")->applyFromArray([
                'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF1565C0']]],
            ]);
        }

        $sheet->freezePane('A7');

        $namaKlien = preg_replace('/[^a-zA-Z0-9\-]/', '_', $report['klien']['kode_klien']);
        $temp = tempnam(sys_get_temp_dir(), 'rk_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, "rekening-koran-{$namaKlien}-" . now()->format('Ymd') . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    public function exportPdf(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $request->validate([
            'klien_ar_id'   => ['required', 'integer', 'exists:tb_klien_ar,id'],
            'periode_awal'  => ['nullable', 'date'],
            'periode_akhir' => ['nullable', 'date', 'after_or_equal:periode_awal'],
        ]);

        $report = $this->service->getReport(
            $request->only(['klien_ar_id', 'periode_awal', 'periode_akhir'])
        );

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('finance.rekening-koran-print', [
            'report'    => $report,
            'printed_at' => now()->format('d/m/Y H:i'),
        ]);

        $pdf->setPaper('A4', 'portrait');
        $pdf->set_option('isHtml5ParserEnabled', true);
        $pdf->set_option('dpi', 150);

        $namaKlien = preg_replace('/[^a-zA-Z0-9\-]/', '_', $report['klien']['kode_klien']);

        return $pdf->download("rekening-koran-{$namaKlien}-" . now()->format('Ymd') . '.pdf');
    }
}
