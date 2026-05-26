<?php

namespace App\Domain\Finance\JurnalPic\Controllers;

use App\Domain\Finance\JurnalPic\Resources\JurnalPicResource;
use App\Domain\Finance\JurnalPic\Services\JurnalPicService;
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

class JurnalPicController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly JurnalPicService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'no_referensi'        => ['nullable', 'string', 'max:100'],
            'karyawan_id'         => ['nullable', 'integer', 'exists:tb_karyawan,id'],
            'metode_pembayaran'   => ['nullable', 'in:TRANSFER,CASH,GIRO'],
            'tanggal_dari'        => ['nullable', 'date'],
            'tanggal_sampai'      => ['nullable', 'date', 'after_or_equal:tanggal_dari'],
            'status_rekonsiliasi' => ['nullable', 'in:MATCHED,POSSIBLE,UNMATCHED,DIABAIKAN'],
            'per_page'            => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filters          = $request->only([
            'no_referensi', 'karyawan_id', 'metode_pembayaran',
            'tanggal_dari', 'tanggal_sampai', 'status_rekonsiliasi', 'per_page',
        ]);
        /** @var \App\Models\User $authUser */
        $authUser         = auth()->user();
        $filters['_user'] = $authUser->load('karyawan');

        $paginator = $this->service->getJurnal($filters);
        $summary   = $this->service->getSummary($filters);
        $items     = $paginator->through(fn($p) => new JurnalPicResource($p));

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data'    => $items->items(),
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
            'summary' => $summary,
        ]);
    }

    public function exportExcel(Request $request): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $request->validate([
            'no_referensi'        => ['nullable', 'string', 'max:100'],
            'karyawan_id'         => ['nullable', 'integer', 'exists:tb_karyawan,id'],
            'metode_pembayaran'   => ['nullable', 'in:TRANSFER,CASH,GIRO'],
            'tanggal_dari'        => ['nullable', 'date'],
            'tanggal_sampai'      => ['nullable', 'date', 'after_or_equal:tanggal_dari'],
            'status_rekonsiliasi' => ['nullable', 'in:MATCHED,POSSIBLE,UNMATCHED,DIABAIKAN'],
        ]);

        $filters          = $request->only([
            'no_referensi', 'karyawan_id', 'metode_pembayaran',
            'tanggal_dari', 'tanggal_sampai', 'status_rekonsiliasi',
        ]);
        /** @var \App\Models\User $authUser */
        $authUser         = auth()->user();
        $filters['_user'] = $authUser->load('karyawan');

        $rows    = $this->service->getJurnalAll($filters);
        $summary = $this->service->getSummary($filters);

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Jurnal PIC AR');

        $lastCol = 'J';

        // Baris 1: Judul
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'JURNAL PIC AR');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        // Baris 2: Summary
        $summaryText = sprintf(
            'Total Transaksi: %d  |  Total Nominal: Rp %s  |  Matched: %d  |  Belum Direkonsiliasi: %d',
            $summary['total_transaksi'],
            number_format($summary['total_nominal'], 0, ',', '.'),
            $summary['total_matched'],
            $summary['total_belum_cocok']
        );
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', $summaryText);
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FF1B5E20']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8F5E9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(20);

        // Baris 3: Info filter
        $periodeText = 'Periode: '
            . ($filters['tanggal_dari'] ?? '-') . ' s/d ' . ($filters['tanggal_sampai'] ?? '-')
            . '   |   Diekspor: ' . now()->format('d-m-Y H:i');
        $sheet->mergeCells("A3:{$lastCol}3");
        $sheet->setCellValue('A3', $periodeText);
        $sheet->getStyle('A3')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF455A64']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8F5E9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(3)->setRowHeight(18);
        $sheet->getRowDimension(4)->setRowHeight(6);

        // Header kolom (baris 5)
        $cols = [
            'A' => ['No',                    5],
            'B' => ['No. Referensi',         22],
            'C' => ['Tanggal Bayar',         14],
            'D' => ['No Invoice',            22],
            'E' => ['Klien',                 28],
            'F' => ['PIC AR',                22],
            'G' => ['Entitas',               16],
            'H' => ['Metode',                12],
            'I' => ['Nominal',               18],
            'J' => ['Status Rekonsiliasi',   22],
        ];

        foreach ($cols as $col => [$label, $width]) {
            $sheet->setCellValue("{$col}5", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A5:{$lastCol}5")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E7D32']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1B5E20']]],
        ]);
        $sheet->getRowDimension(5)->setRowHeight(22);

        // Baris data
        $rowNum = 6;
        foreach ($rows as $i => $p) {
            $bg = $rowNum % 2 === 0 ? 'FFF1F8E9' : 'FFFFFFFF';

            $bankDetail = $p->bankStatementDetail
                ?: $p->sumberPembayaran?->bankStatementDetail;

            $sheet->getCell("A{$rowNum}")->setValueExplicit($i + 1, DataType::TYPE_NUMERIC);
            $sheet->getCell("B{$rowNum}")->setValueExplicit($p->no_referensi ?? '', DataType::TYPE_STRING);
            $sheet->getCell("C{$rowNum}")->setValueExplicit($p->tanggal_pembayaran?->format('Y-m-d') ?? '', DataType::TYPE_STRING);
            $sheet->getCell("D{$rowNum}")->setValueExplicit($p->invoice?->no_invoice ?? '', DataType::TYPE_STRING);
            $sheet->getCell("E{$rowNum}")->setValueExplicit($p->invoice?->klienAr?->nama_klien ?? '', DataType::TYPE_STRING);
            $sheet->getCell("F{$rowNum}")->setValueExplicit($p->invoice?->karyawan?->nama_karyawan ?? '', DataType::TYPE_STRING);
            $sheet->getCell("G{$rowNum}")->setValueExplicit($p->invoice?->perusahaan?->nama_singkatan_perusahaan ?? '', DataType::TYPE_STRING);
            $sheet->getCell("H{$rowNum}")->setValueExplicit($p->metode_pembayaran ?? '', DataType::TYPE_STRING);
            $sheet->getCell("I{$rowNum}")->setValueExplicit((float) $p->jumlah_pembayaran, DataType::TYPE_NUMERIC);
            $sheet->getStyle("I{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getCell("J{$rowNum}")->setValueExplicit($bankDetail?->status_cocok ?? 'Belum Diproses', DataType::TYPE_STRING);

            $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($rowNum)->setRowHeight(18);
            $rowNum++;
        }

        // Baris total
        $sheet->mergeCells("A{$rowNum}:H{$rowNum}");
        $sheet->setCellValue("A{$rowNum}", 'TOTAL NOMINAL');
        $sheet->getCell("I{$rowNum}")->setValueExplicit((float) $summary['total_nominal'], DataType::TYPE_NUMERIC);
        $sheet->getStyle("I{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF1B5E20']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8F5E9']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF2E7D32']]],
        ]);
        $sheet->getRowDimension($rowNum)->setRowHeight(20);

        if ($rowNum > 6) {
            $sheet->getStyle("A5:{$lastCol}{$rowNum}")->applyFromArray([
                'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF2E7D32']]],
            ]);
        }

        $sheet->freezePane('A6');

        $temp = tempnam(sys_get_temp_dir(), 'jp_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'jurnal-pic-ar-' . now()->format('Ymd') . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }
}
