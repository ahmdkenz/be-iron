<?php

namespace App\Domain\Finance\TagihanAp\Services;

use App\Models\TagihanAp;
use App\Models\TagihanApItem;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TagihanApExportService
{
    private const REKAP_START_COL_INDEX = 1; // A

    private const REKAP_COLS = [
        ['No', 5],
        ['No Tagihan', 20],
        ['No Invoice Vendor', 20],
        ['Vendor', 28],
        ['Kode Vendor', 14],
        ['Entitas', 16],
        ['PIC AP', 20],
        ['Tanggal Tagihan', 16],
        ['Tanggal Jatuh Tempo', 18],
        ['No PO', 16],
        ['No Terima Barang', 18],
        ['Subtotal', 16],
        ['PPN Masukan', 14],
        ['PPH23', 14],
        ['Total Tagihan', 16],
        ['Total Pembayaran', 16],
        ['Sisa Tagihan', 16],
        ['Status', 12],
        ['Approval', 14],
        ['Keterangan', 30],
        ['Dibuat Oleh', 20],
        ['Dibuat Pada', 16],
    ];

    private const REKAP_INT_OFFSETS = [0];

    private const REKAP_MONEY_OFFSETS = [11, 12, 13, 14, 15, 16];

    private const DETAIL_START_COL_INDEX = 24; // X

    private const DETAIL_COLS = [
        ['No', 5],
        ['No Tagihan', 20],
        ['No Invoice Vendor', 20],
        ['Tanggal Tagihan', 16],
        ['Vendor', 28],
        ['Entitas', 16],
        ['No PO', 16],
        ['No Terima Barang', 18],
        ['Kode Barang', 16],
        ['Nama Barang', 28],
        ['Qty', 12],
        ['Qty PO', 12],
        ['Satuan', 10],
        ['Harga Satuan', 16],
        ['PPN', 14],
        ['Subtotal Item', 16],
        ['Status Terima PO', 18],
        ['Qty Tolak', 12],
        ['Keterangan Tolak', 26],
        ['Keterangan Item', 30],
    ];

    private const DETAIL_INT_OFFSETS = [0];

    private const DETAIL_QTY_OFFSETS = [10, 11, 17];

    private const DETAIL_MONEY_OFFSETS = [13, 14, 15];

    /**
     * @param Collection<int, TagihanAp> $tagihanList
     */
    public function build(Collection $tagihanList): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Tagihan AP');
        $sheet->getRowDimension(1)->setRowHeight(26);
        $sheet->getRowDimension(2)->setRowHeight(6);
        $sheet->getRowDimension(3)->setRowHeight(30);

        $this->writeRekapTable($sheet, $tagihanList);
        $this->writeDetailTable($sheet, $tagihanList);

        $sheet->freezePane('A4');

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function writeRekapTable(Worksheet $sheet, Collection $tagihanList): void
    {
        $colLetters = $this->columnLetters(self::REKAP_START_COL_INDEX, count(self::REKAP_COLS));
        $firstCol   = $colLetters[0];
        $lastCol    = end($colLetters);

        $sheet->mergeCells("{$firstCol}1:{$lastCol}1");
        $sheet->setCellValue("{$firstCol}1", 'TAGIHAN AP');
        $this->styleTitle($sheet, "{$firstCol}1:{$lastCol}1");

        foreach (self::REKAP_COLS as $offset => [$label, $width]) {
            $col = $colLetters[$offset];
            $sheet->setCellValue("{$col}3", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $this->styleHeaderRow($sheet, "{$firstCol}3:{$lastCol}3");

        $rowNum = 4;
        foreach ($tagihanList as $i => $tagihan) {
            $values = $this->rekapRowValues($i + 1, $tagihan);
            $this->writeRow($sheet, $rowNum, $colLetters, $values, self::REKAP_INT_OFFSETS, [], self::REKAP_MONEY_OFFSETS);
            $rowNum++;
        }

        if ($tagihanList->isEmpty()) {
            $sheet->mergeCells("{$firstCol}{$rowNum}:{$lastCol}{$rowNum}");
            $sheet->setCellValue("{$firstCol}{$rowNum}", 'Tidak ada data');
            $sheet->getStyle("{$firstCol}{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
    }

    private function rekapRowValues(int $no, TagihanAp $tagihan): array
    {
        return [
            $no,
            $tagihan->no_tagihan ?? '',
            $tagihan->no_invoice_vendor ?? '',
            $tagihan->vendorAp?->nama_vendor ?? '',
            $tagihan->vendorAp?->kode_vendor ?? '',
            $tagihan->perusahaan?->nama_singkatan_perusahaan ?? '',
            $tagihan->karyawan?->nama_karyawan ?? '',
            optional($tagihan->tanggal_tagihan)->format('d-m-Y') ?? '',
            optional($tagihan->tanggal_jatuh_tempo)->format('d-m-Y') ?? '',
            $tagihan->no_po ?? '',
            $tagihan->no_terima_barang ?? '',
            (float) $tagihan->subtotal,
            (float) $tagihan->ppn_masukan,
            (float) $tagihan->pph23,
            (float) $tagihan->total_tagihan,
            (float) $tagihan->total_pembayaran,
            (float) $tagihan->sisa_tagihan,
            $tagihan->status ?? '',
            $tagihan->approval_status ?? '',
            $tagihan->keterangan ?? '',
            $tagihan->createdBy?->karyawan?->nama_karyawan ?? $tagihan->createdBy?->username ?? '',
            optional($tagihan->created_at)->format('d-m-Y H:i') ?? '',
        ];
    }

    private function writeDetailTable(Worksheet $sheet, Collection $tagihanList): void
    {
        $colLetters = $this->columnLetters(self::DETAIL_START_COL_INDEX, count(self::DETAIL_COLS));
        $firstCol   = $colLetters[0];
        $lastCol    = end($colLetters);

        $sheet->mergeCells("{$firstCol}1:{$lastCol}1");
        $sheet->setCellValue("{$firstCol}1", 'TAGIHAN AP DETAIL');
        $this->styleTitle($sheet, "{$firstCol}1:{$lastCol}1");

        foreach (self::DETAIL_COLS as $offset => [$label, $width]) {
            $col = $colLetters[$offset];
            $sheet->setCellValue("{$col}3", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $this->styleHeaderRow($sheet, "{$firstCol}3:{$lastCol}3");

        $rowNum = 4;
        foreach ($tagihanList as $tagihan) {
            foreach ($tagihan->items as $item) {
                $values = $this->detailRowValues($rowNum - 3, $tagihan, $item);
                $this->writeRow($sheet, $rowNum, $colLetters, $values, self::DETAIL_INT_OFFSETS, self::DETAIL_QTY_OFFSETS, self::DETAIL_MONEY_OFFSETS);
                $rowNum++;
            }
        }

        if ($rowNum === 4) {
            $sheet->mergeCells("{$firstCol}{$rowNum}:{$lastCol}{$rowNum}");
            $sheet->setCellValue("{$firstCol}{$rowNum}", 'Tidak ada data');
            $sheet->getStyle("{$firstCol}{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
    }

    private function detailRowValues(int $no, TagihanAp $tagihan, TagihanApItem $item): array
    {
        return [
            $no,
            $tagihan->no_tagihan ?? '',
            $tagihan->no_invoice_vendor ?? '',
            optional($tagihan->tanggal_tagihan)->format('d-m-Y') ?? '',
            $tagihan->vendorAp?->nama_vendor ?? '',
            $tagihan->perusahaan?->nama_singkatan_perusahaan ?? '',
            $tagihan->no_po ?? '',
            $tagihan->no_terima_barang ?? '',
            $item->kode_barang ?? '',
            $item->nama_barang ?? '',
            (float) $item->qty,
            $item->qty_po !== null ? (float) $item->qty_po : null,
            $item->satuan ?? '',
            (float) $item->harga_satuan,
            (float) $item->ppn,
            (float) $item->subtotal,
            $item->status_detail_terima_po ?? '',
            $item->qty_tolak !== null ? (float) $item->qty_tolak : null,
            $item->keterangan_tolak ?? '',
            $item->keterangan ?? '',
        ];
    }

    /**
     * @param string[] $colLetters
     * @param array<int, mixed> $values
     * @param int[] $intOffsets
     * @param int[] $qtyOffsets
     * @param int[] $moneyOffsets
     */
    private function writeRow(
        Worksheet $sheet,
        int $rowNum,
        array $colLetters,
        array $values,
        array $intOffsets,
        array $qtyOffsets,
        array $moneyOffsets,
    ): void {
        foreach ($values as $offset => $value) {
            $col  = $colLetters[$offset];
            $cell = $sheet->getCell("{$col}{$rowNum}");

            if ($value === null || $value === '') {
                $cell->setValueExplicit('', DataType::TYPE_STRING);
                continue;
            }

            if (in_array($offset, $intOffsets, true)) {
                $cell->setValueExplicit((int) $value, DataType::TYPE_NUMERIC);
            } elseif (in_array($offset, $qtyOffsets, true)) {
                $cell->setValueExplicit((float) $value, DataType::TYPE_NUMERIC);
                $sheet->getStyle("{$col}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0.###');
            } elseif (in_array($offset, $moneyOffsets, true)) {
                $cell->setValueExplicit((float) $value, DataType::TYPE_NUMERIC);
                $sheet->getStyle("{$col}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
            } else {
                $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);
            }
        }

        $bg      = $rowNum % 2 === 0 ? 'FFE3F2FD' : 'FFFFFFFF';
        $lastCol = end($colLetters);
        $sheet->getStyle("{$colLetters[0]}{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
    }

    private function styleTitle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
    }

    private function styleHeaderRow(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF37474F']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF263238']]],
        ]);
    }

    /**
     * @return string[]
     */
    private function columnLetters(int $startIndex, int $count): array
    {
        $letters = [];
        for ($i = 0; $i < $count; $i++) {
            $letters[] = Coordinate::stringFromColumnIndex($startIndex + $i);
        }

        return $letters;
    }
}
