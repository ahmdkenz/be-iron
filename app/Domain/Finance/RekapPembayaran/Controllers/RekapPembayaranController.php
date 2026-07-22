<?php

namespace App\Domain\Finance\RekapPembayaran\Controllers;

use App\Domain\Finance\RekapPembayaran\Services\RekapPembayaranService;
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

class RekapPembayaranController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RekapPembayaranService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'tanggal_dari'      => ['nullable', 'date'],
            'tanggal_sampai'    => ['nullable', 'date', 'after_or_equal:tanggal_dari'],
            'klien_ar_id'       => ['nullable', 'integer', 'exists:tb_klien_ar,id'],
            'metode_pembayaran' => ['nullable', 'in:TRANSFER,CASH,GIRO'],
            'segment'           => ['nullable', 'in:B2B,B2C,ALL'],
            'page'              => ['nullable', 'integer', 'min:1'],
            'per_page'          => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filters = $request->only(['tanggal_dari', 'tanggal_sampai', 'klien_ar_id', 'metode_pembayaran', 'segment']);
        ArFilterScope::apply($filters, $request->user());

        $report = $this->service->getReport($filters);

        if ($perPage = $request->integer('per_page')) {
            ['items' => $report['rows'], 'meta' => $report['meta']]
                = $this->paginateArray($report['rows'], $request->integer('page', 1), $perPage);
        }

        return $this->successResponse($report);
    }

    public function exportExcel(Request $request): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $request->validate([
            'tanggal_dari'      => ['nullable', 'date'],
            'tanggal_sampai'    => ['nullable', 'date', 'after_or_equal:tanggal_dari'],
            'klien_ar_id'       => ['nullable', 'integer', 'exists:tb_klien_ar,id'],
            'metode_pembayaran' => ['nullable', 'in:TRANSFER,CASH,GIRO'],
            'segment'           => ['nullable', 'in:B2B,B2C,ALL'],
        ]);

        $filters = $request->only(['tanggal_dari', 'tanggal_sampai', 'klien_ar_id', 'metode_pembayaran', 'segment']);
        ArFilterScope::apply($filters, $request->user());

        $report = $this->service->getReport($filters);

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rekap Pembayaran');

        $lastCol = 'I';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'REKAP PEMBAYARAN');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $periodeText = 'Periode: ' . $report['tanggal_dari'] . ' s/d ' . $report['tanggal_sampai']
            . '   |   Diekspor: ' . now()->format('d-m-Y H:i');
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', $periodeText);
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF455A64']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE3F2FD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(18);

        $summaryText = sprintf(
            'Total: Rp %s  |  Transfer: Rp %s  |  Cash: Rp %s  |  Giro: Rp %s  |  Transaksi: %d',
            number_format($report['summary']['total'], 0, ',', '.'),
            number_format($report['summary']['transfer'], 0, ',', '.'),
            number_format($report['summary']['cash'], 0, ',', '.'),
            number_format($report['summary']['giro'], 0, ',', '.'),
            $report['summary']['jumlah_transaksi']
        );
        $sheet->mergeCells("A3:{$lastCol}3");
        $sheet->setCellValue('A3', $summaryText);
        $sheet->getStyle('A3')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FF1565C0']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE3F2FD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(3)->setRowHeight(20);
        $sheet->getRowDimension(4)->setRowHeight(6);

        $cols = [
            'A' => ['No',          5],
            'B' => ['Tanggal',    14],
            'C' => ['Client',     30],
            'D' => ['Invoice',    20],
            'E' => ['Ref Payment', 22],
            'F' => ['Metode',     12],
            'G' => ['Nominal',    20],
            'H' => ['PIC AR',     22],
            'I' => ['Rekon',       8],
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

        $rowNum = 6;
        foreach ($report['rows'] as $i => $row) {
            $bg = $rowNum % 2 === 0 ? 'FFE3F2FD' : 'FFFFFFFF';

            $sheet->getCell("A{$rowNum}")->setValueExplicit($i + 1, DataType::TYPE_NUMERIC);
            $sheet->getCell("B{$rowNum}")->setValueExplicit($row['tanggal'], DataType::TYPE_STRING);
            $sheet->getCell("C{$rowNum}")->setValueExplicit($row['client'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("D{$rowNum}")->setValueExplicit($row['invoice'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("E{$rowNum}")->setValueExplicit($row['ref_payment'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("F{$rowNum}")->setValueExplicit($row['metode'] ?? '', DataType::TYPE_STRING);
            $nominal = (float) ($row['nominal'] ?? 0);
            $sheet->getCell("G{$rowNum}")->setValueExplicit($nominal, DataType::TYPE_NUMERIC);
            $sheet->getStyle("G{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getCell("H{$rowNum}")->setValueExplicit($row['pic_ar'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("I{$rowNum}")->setValueExplicit($row['is_rekon'] ? '✓' : '', DataType::TYPE_STRING);

            $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getStyle("I{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getRowDimension($rowNum)->setRowHeight(18);
            $rowNum++;
        }

        $summary = $report['summary'];
        $sheet->mergeCells("A{$rowNum}:F{$rowNum}");
        $sheet->setCellValue("A{$rowNum}", 'TOTAL');
        $sheet->getCell("G{$rowNum}")->setValueExplicit((float) ($summary['total'] ?? 0), DataType::TYPE_NUMERIC);
        $sheet->getStyle("G{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF1565C0']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE3F2FD']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1565C0']]],
        ]);
        $sheet->getRowDimension($rowNum)->setRowHeight(20);

        $sheet->freezePane('A6');

        $temp = tempnam(sys_get_temp_dir(), 'rp_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'rekap-pembayaran-' . now()->format('Ymd') . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }
}
