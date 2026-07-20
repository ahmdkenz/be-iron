<?php

namespace App\Domain\Finance\PembayaranAp\Services;

use App\Models\PembayaranAp;
use App\Models\PembayaranApItem;
use App\Models\TagihanApItem;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PembayaranApExportService
{
    private const REKAP_START_COL_INDEX = 1; // A

    private const REKAP_COLS = [
        ['No', 5],
        ['Tanggal Pembayaran', 16],
        ['No Voucher', 22],
        ['Metode', 12],
        ['Entitas', 16],
        ['Vendor', 32],
        ['Jumlah Vendor', 13],
        ['Jumlah Tagihan', 13],
        ['Total Pembayaran', 18],
        ['Keterangan', 30],
        ['Dibuat Oleh', 20],
        ['Dibuat Pada', 16],
    ];

    private const REKAP_MONEY_OFFSETS = [8];

    private const REKAP_INT_OFFSETS = [0, 6, 7];

    private const DETAIL_START_COL_INDEX = 14; // N

    private const DETAIL_COLS = [
        ['No', 5],
        ['Tanggal Pembayaran', 16],
        ['No Voucher', 22],
        ['Metode', 12],
        ['Entitas', 16],
        ['Vendor', 26],
        ['PIC AP', 20],
        ['No Tagihan', 20],
        ['No Invoice Vendor', 20],
        ['Tanggal Tagihan', 16],
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
        ['Total Tagihan', 16],
        ['Sisa Sebelum', 16],
        ['Dibayar', 16],
        ['Sisa Setelah', 16],
        ['Keterangan Item', 30],
    ];

    private const DETAIL_INT_OFFSETS = [0];

    private const DETAIL_QTY_OFFSETS = [14, 15];

    private const DETAIL_MONEY_OFFSETS = [17, 18, 19, 20, 21, 22, 23];

    /**
     * @param Collection<int, PembayaranAp> $vouchers
     */
    public function build(Collection $vouchers): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $this->buildSheet($spreadsheet, 'Bahan Baku', $vouchers->filter(
            fn(PembayaranAp $v) => $v->kategori_voucher === 'BB'
        )->values());

        $this->buildSheet($spreadsheet, 'Non Bahan Baku', $vouchers->filter(
            fn(PembayaranAp $v) => $v->kategori_voucher === 'NBB'
        )->values());

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function buildSheet(Spreadsheet $spreadsheet, string $title, Collection $vouchers): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);
        $sheet->getRowDimension(1)->setRowHeight(26);
        $sheet->getRowDimension(2)->setRowHeight(6);
        $sheet->getRowDimension(3)->setRowHeight(30);

        $this->writeRekapTable($sheet, $vouchers);
        $this->writeDetailTable($sheet, $vouchers);

        $sheet->freezePane('A4');
    }

    private function writeRekapTable(Worksheet $sheet, Collection $vouchers): void
    {
        $colLetters = $this->columnLetters(self::REKAP_START_COL_INDEX, count(self::REKAP_COLS));
        $firstCol   = $colLetters[0];
        $lastCol    = end($colLetters);

        $sheet->mergeCells("{$firstCol}1:{$lastCol}1");
        $sheet->setCellValue("{$firstCol}1", 'REKAP PAYMENT VOUCHER');
        $this->styleTitle($sheet, "{$firstCol}1:{$lastCol}1");

        foreach (self::REKAP_COLS as $offset => [$label, $width]) {
            $col = $colLetters[$offset];
            $sheet->setCellValue("{$col}3", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $this->styleHeaderRow($sheet, "{$firstCol}3:{$lastCol}3");

        $rowNum = 4;
        foreach ($vouchers as $i => $voucher) {
            $values = $this->rekapRowValues($i + 1, $voucher);
            $this->writeRow($sheet, $rowNum, $colLetters, $values, self::REKAP_INT_OFFSETS, [], self::REKAP_MONEY_OFFSETS);
            $rowNum++;
        }

        if ($vouchers->isEmpty()) {
            $sheet->mergeCells("{$firstCol}{$rowNum}:{$lastCol}{$rowNum}");
            $sheet->setCellValue("{$firstCol}{$rowNum}", 'Tidak ada data');
            $sheet->getStyle("{$firstCol}{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
    }

    private function rekapRowValues(int $no, PembayaranAp $voucher): array
    {
        $items = $voucher->items;

        $vendorNames = $items
            ->map(fn(PembayaranApItem $it) => $it->tagihanAp?->vendorAp?->nama_vendor ?? $it->vendorAp?->nama_vendor)
            ->filter()
            ->unique()
            ->values();

        $entitasNames = $items
            ->map(fn(PembayaranApItem $it) => $it->tagihanAp?->perusahaan?->nama_singkatan_perusahaan)
            ->filter()
            ->unique()
            ->values();

        return [
            $no,
            optional($voucher->tanggal_pembayaran)->format('d-m-Y') ?? '',
            $voucher->no_referensi ?? '',
            $voucher->metode_pembayaran ?? '',
            $entitasNames->implode(', '),
            $vendorNames->implode(', '),
            $vendorNames->count(),
            $items->count(),
            (float) $voucher->jumlah_pembayaran,
            $voucher->keterangan ?? '',
            $voucher->createdBy?->karyawan?->nama_karyawan ?? $voucher->createdBy?->username ?? '',
            optional($voucher->created_at)->format('d-m-Y H:i') ?? '',
        ];
    }

    private function writeDetailTable(Worksheet $sheet, Collection $vouchers): void
    {
        $colLetters = $this->columnLetters(self::DETAIL_START_COL_INDEX, count(self::DETAIL_COLS));
        $firstCol   = $colLetters[0];
        $lastCol    = end($colLetters);

        $sheet->mergeCells("{$firstCol}1:{$lastCol}1");
        $sheet->setCellValue("{$firstCol}1", 'DETAIL ITEM TAGIHAN');
        $this->styleTitle($sheet, "{$firstCol}1:{$lastCol}1");

        foreach (self::DETAIL_COLS as $offset => [$label, $width]) {
            $col = $colLetters[$offset];
            $sheet->setCellValue("{$col}3", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $this->styleHeaderRow($sheet, "{$firstCol}3:{$lastCol}3");

        $rowNum = 4;
        foreach ($vouchers as $voucher) {
            foreach ($voucher->items as $alokasi) {
                $barangItems = $alokasi->tagihanAp?->items ?? collect();

                if ($barangItems->isEmpty()) {
                    $values = $this->detailRowValues($rowNum - 3, $voucher, $alokasi, null);
                    $this->writeRow($sheet, $rowNum, $colLetters, $values, self::DETAIL_INT_OFFSETS, self::DETAIL_QTY_OFFSETS, self::DETAIL_MONEY_OFFSETS);
                    $rowNum++;
                    continue;
                }

                foreach ($barangItems as $barang) {
                    $values = $this->detailRowValues($rowNum - 3, $voucher, $alokasi, $barang);
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

    private function detailRowValues(int $no, PembayaranAp $voucher, PembayaranApItem $alokasi, ?TagihanApItem $barang): array
    {
        $tagihan    = $alokasi->tagihanAp;
        $vendorNama = $tagihan?->vendorAp?->nama_vendor ?? $alokasi->vendorAp?->nama_vendor;
        $entitas    = $tagihan?->perusahaan?->nama_singkatan_perusahaan;
        $picAp      = $tagihan?->karyawan?->nama_karyawan;

        return [
            $no,
            optional($voucher->tanggal_pembayaran)->format('d-m-Y') ?? '',
            $voucher->no_referensi ?? '',
            $voucher->metode_pembayaran ?? '',
            $entitas ?? '',
            $vendorNama ?? '',
            $picAp ?? '',
            $tagihan?->no_tagihan ?? '',
            $tagihan?->no_invoice_vendor ?? '',
            optional($tagihan?->tanggal_tagihan)->format('d-m-Y') ?? '',
            $tagihan?->no_po ?? '',
            $tagihan?->no_terima_barang ?? '',
            $barang?->kode_barang ?? '',
            $barang?->nama_barang ?? '',
            $barang ? (float) $barang->qty : null,
            $barang ? (float) $barang->qty_po : null,
            $barang?->satuan ?? '',
            $barang ? (float) $barang->harga_satuan : null,
            $barang ? (float) $barang->ppn : null,
            $barang ? (float) $barang->subtotal : null,
            (float) ($tagihan?->total_tagihan ?? 0),
            (float) $alokasi->sisa_sebelum,
            (float) $alokasi->jumlah_dialokasikan,
            (float) $alokasi->sisa_sesudah,
            $barang?->keterangan ?? '',
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
