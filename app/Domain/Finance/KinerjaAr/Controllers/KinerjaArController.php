<?php

namespace App\Domain\Finance\KinerjaAr\Controllers;

use App\Domain\Finance\KinerjaAr\Services\KinerjaArService;
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

class KinerjaArController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly KinerjaArService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'periode_awal'   => ['nullable', 'date'],
            'periode_akhir'  => ['nullable', 'date', 'after_or_equal:periode_awal'],
            'karyawan_ar_id' => ['nullable', 'integer', 'exists:tb_karyawan,id'],
            'segment'        => ['nullable', 'in:B2B,B2C,ALL'],
        ]);

        $report = $this->service->getReport(
            $request->only(['periode_awal', 'periode_akhir', 'karyawan_ar_id', 'segment'])
        );

        return $this->successResponse($report);
    }

    public function exportExcel(Request $request): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $request->validate([
            'periode_awal'   => ['nullable', 'date'],
            'periode_akhir'  => ['nullable', 'date', 'after_or_equal:periode_awal'],
            'karyawan_ar_id' => ['nullable', 'integer', 'exists:tb_karyawan,id'],
            'segment'        => ['nullable', 'in:B2B,B2C,ALL'],
        ]);

        $report = $this->service->getReport(
            $request->only(['periode_awal', 'periode_akhir', 'karyawan_ar_id', 'segment'])
        );

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Kinerja AR');

        $lastCol = 'I';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'KINERJA AR OFFICER');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF00695C']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $periodeText = 'Periode: ' . $report['periode_awal'] . ' s/d ' . $report['periode_akhir']
            . '   |   Diekspor: ' . now()->format('d-m-Y H:i');
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', $periodeText);
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF455A64']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE0F2F1']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(18);
        $sheet->getRowDimension(3)->setRowHeight(6);

        $cols = [
            'A' => ['No',              5],
            'B' => ['AR Officer',      28],
            'C' => ['Entitas',         14],
            'D' => ['Jml Klien',       12],
            'E' => ['Jml Invoice',     14],
            'F' => ['Total Tagihan',   22],
            'G' => ['Terkumpul',       22],
            'H' => ['Sisa',            22],
            'I' => ['Collection Rate', 18],
        ];

        foreach ($cols as $col => [$label, $width]) {
            $sheet->setCellValue("{$col}4", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF00796B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF00695C']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(22);

        $rowNum = 5;
        foreach ($report['rows'] as $i => $row) {
            $bg = $rowNum % 2 === 0 ? 'FFE0F2F1' : 'FFFFFFFF';

            $sheet->getCell("A{$rowNum}")->setValueExplicit($i + 1, DataType::TYPE_NUMERIC);
            $sheet->getCell("B{$rowNum}")->setValueExplicit($row['nama_karyawan'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("C{$rowNum}")->setValueExplicit($row['perusahaan'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("D{$rowNum}")->setValueExplicit((int) ($row['jumlah_klien'] ?? 0), DataType::TYPE_NUMERIC);
            $sheet->getCell("E{$rowNum}")->setValueExplicit((int) ($row['jumlah_invoice'] ?? 0), DataType::TYPE_NUMERIC);

            foreach (['total_tagihan' => 'F', 'total_terkumpul' => 'G', 'total_sisa' => 'H'] as $field => $col) {
                $val = (float) ($row[$field] ?? 0);
                $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val, DataType::TYPE_NUMERIC);
                $sheet->getStyle("{$col}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
            }
            $sheet->getCell("I{$rowNum}")->setValueExplicit(
                ($row['collection_rate'] ?? 0) . '%',
                DataType::TYPE_STRING
            );

            $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($rowNum)->setRowHeight(18);
            $rowNum++;
        }

        $summary = $report['summary'];
        $sheet->mergeCells("A{$rowNum}:E{$rowNum}");
        $sheet->setCellValue("A{$rowNum}", 'TOTAL');
        foreach (['total_tagihan' => 'F', 'total_terkumpul' => 'G', 'total_sisa' => 'H'] as $field => $col) {
            $sheet->getCell("{$col}{$rowNum}")->setValueExplicit((float) ($summary[$field] ?? 0), DataType::TYPE_NUMERIC);
            $sheet->getStyle("{$col}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
        }
        $sheet->getCell("I{$rowNum}")->setValueExplicit(($summary['collection_rate'] ?? 0) . '%', DataType::TYPE_STRING);
        $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
            'font'    => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF00695C']],
            'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE0F2F1']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF00796B']]],
        ]);
        $sheet->getRowDimension($rowNum)->setRowHeight(20);

        $sheet->freezePane('A5');

        $temp = tempnam(sys_get_temp_dir(), 'ka_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'kinerja-ar-' . now()->format('Ymd') . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }
}
