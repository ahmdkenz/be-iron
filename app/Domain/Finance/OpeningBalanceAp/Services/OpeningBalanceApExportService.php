<?php

namespace App\Domain\Finance\OpeningBalanceAp\Services;

use App\Models\OpeningBalanceApDetail;
use App\Models\OpeningBalanceApDetailItem;
use App\Models\TagihanAp;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OpeningBalanceApExportService
{
    private const REKAP_START_COL_INDEX = 1; // A

    private const REKAP_COLS = [
        ['No', 5],
        ['No OB', 20],
        ['Vendor', 28],
        ['Kode Vendor', 14],
        ['Entitas', 16],
        ['PIC AP', 20],
        ['Tanggal OB', 14],
        ['Jatuh Tempo', 14],
        ['Saldo Awal', 16],
        ['Terbayar', 16],
        ['Penyesuaian', 16],
        ['Sisa Hutang', 16],
        ['Status', 12],
        ['Approval', 14],
        ['Keterangan', 30],
        ['Diajukan Oleh', 20],
        ['Diajukan Pada', 16],
        ['Disetujui Oleh', 20],
        ['Disetujui Pada', 16],
        ['Ditolak Oleh', 20],
        ['Ditolak Pada', 16],
        ['Dibuat Oleh', 20],
        ['Dibuat Pada', 16],
    ];

    private const REKAP_INT_OFFSETS = [0];

    private const REKAP_MONEY_OFFSETS = [8, 9, 10, 11];

    private const DETAIL_START_COL_INDEX = 1; // A (sheet terpisah dari rekap)

    private const DETAIL_COLS = [
        ['No', 5],
        ['No OB', 20],
        ['Vendor', 28],
        ['No Invoice Asal', 20],
        ['Tanggal Invoice Asal', 18],
        ['Deskripsi', 30],
        ['Jumlah Tagihan Asal', 18],
        ['Sisa Tagihan Asal', 18],
        ['Kode Barang', 16],
        ['Nama Barang', 28],
        ['Qty', 12],
        ['Satuan', 10],
        ['Harga Satuan', 16],
        ['Subtotal Item', 16],
        ['Keterangan', 30],
    ];

    private const DETAIL_INT_OFFSETS = [0];

    private const DETAIL_QTY_OFFSETS = [10];

    private const DETAIL_MONEY_OFFSETS = [6, 7, 12, 13];

    /**
     * @param Collection<int, TagihanAp> $tagihanList
     */
    public function build(Collection $tagihanList): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $rekapSheet = $spreadsheet->createSheet();
        $rekapSheet->setTitle('Opening Balance AP');
        $this->prepareSheetRows($rekapSheet);
        $this->writeRekapTable($rekapSheet, $tagihanList);
        $rekapSheet->freezePane('A4');

        $detailSheet = $spreadsheet->createSheet();
        $detailSheet->setTitle('Detail Opening Balance AP');
        $this->prepareSheetRows($detailSheet);
        $this->writeDetailTable($detailSheet, $tagihanList);
        $detailSheet->freezePane('A4');

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function prepareSheetRows(Worksheet $sheet): void
    {
        $sheet->getRowDimension(1)->setRowHeight(26);
        $sheet->getRowDimension(2)->setRowHeight(6);
        $sheet->getRowDimension(3)->setRowHeight(30);
    }

    private function writeRekapTable(Worksheet $sheet, Collection $tagihanList): void
    {
        $colLetters = $this->columnLetters(self::REKAP_START_COL_INDEX, count(self::REKAP_COLS));
        $firstCol   = $colLetters[0];
        $lastCol    = end($colLetters);

        $sheet->mergeCells("{$firstCol}1:{$lastCol}1");
        $sheet->setCellValue("{$firstCol}1", 'OPENING BALANCE AP');
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
            $tagihan->vendorAp?->nama_vendor ?? '',
            $tagihan->vendorAp?->kode_vendor ?? '',
            $tagihan->perusahaan?->nama_singkatan_perusahaan ?? '',
            $tagihan->karyawan?->nama_karyawan ?? '',
            optional($tagihan->tanggal_tagihan)->format('d-m-Y') ?? '',
            optional($tagihan->tanggal_jatuh_tempo)->format('d-m-Y') ?? '',
            (float) $tagihan->total_tagihan,
            (float) $tagihan->total_pembayaran,
            (float) $tagihan->total_penyesuaian,
            (float) $tagihan->sisa_tagihan,
            $tagihan->status ?? '',
            $tagihan->approval_status ?? '',
            $tagihan->keterangan ?? '',
            $tagihan->submittedBy?->karyawan?->nama_karyawan ?? $tagihan->submittedBy?->username ?? '',
            optional($tagihan->submitted_at)->format('d-m-Y H:i') ?? '',
            $tagihan->approvedBy?->karyawan?->nama_karyawan ?? $tagihan->approvedBy?->username ?? '',
            optional($tagihan->approved_at)->format('d-m-Y H:i') ?? '',
            $tagihan->rejectedBy?->karyawan?->nama_karyawan ?? $tagihan->rejectedBy?->username ?? '',
            optional($tagihan->rejected_at)->format('d-m-Y H:i') ?? '',
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
        $sheet->setCellValue("{$firstCol}1", 'DETAIL OPENING BALANCE AP');
        $this->styleTitle($sheet, "{$firstCol}1:{$lastCol}1");

        foreach (self::DETAIL_COLS as $offset => [$label, $width]) {
            $col = $colLetters[$offset];
            $sheet->setCellValue("{$col}3", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $this->styleHeaderRow($sheet, "{$firstCol}3:{$lastCol}3");

        $rowNum = 4;
        foreach ($tagihanList as $tagihan) {
            foreach ($tagihan->openingBalanceApDetails as $detail) {
                $items = $detail->items;

                if ($items->isEmpty()) {
                    $values = $this->detailRowValues($rowNum - 3, $tagihan, $detail, null);
                    $this->writeRow($sheet, $rowNum, $colLetters, $values, self::DETAIL_INT_OFFSETS, self::DETAIL_QTY_OFFSETS, self::DETAIL_MONEY_OFFSETS);
                    $rowNum++;
                    continue;
                }

                foreach ($items as $item) {
                    $values = $this->detailRowValues($rowNum - 3, $tagihan, $detail, $item);
                    $this->writeRow($sheet, $rowNum, $colLetters, $values, self::DETAIL_INT_OFFSETS, self::DETAIL_QTY_OFFSETS, self::DETAIL_MONEY_OFFSETS);
                    $rowNum++;
                }
            }
        }

        if ($rowNum === 4) {
            $sheet->mergeCells("{$firstCol}{$rowNum}:{$lastCol}{$rowNum}");
            $sheet->setCellValue("{$firstCol}{$rowNum}", 'Tidak ada data');
            $sheet->getStyle("{$firstCol}{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
    }

    private function detailRowValues(
        int $no,
        TagihanAp $tagihan,
        OpeningBalanceApDetail $detail,
        ?OpeningBalanceApDetailItem $item,
    ): array {
        return [
            $no,
            $tagihan->no_tagihan ?? '',
            $tagihan->vendorAp?->nama_vendor ?? '',
            $detail->no_invoice_asal ?? '',
            optional($detail->tanggal_invoice_asal)->format('d-m-Y') ?? '',
            $detail->deskripsi ?? '',
            (float) $detail->jumlah_tagihan_asal,
            (float) $detail->sisa_tagihan_asal,
            $item?->kode_barang ?? $item?->barang?->kode_barang ?? '',
            $item?->nama_barang ?? '',
            $item ? (float) $item->qty : null,
            $item?->satuan ?? '',
            $item ? (float) $item->harga_satuan : null,
            $item ? (float) $item->subtotal : null,
            $detail->keterangan ?? '',
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
