<?php

namespace App\Domain\Finance\ExportData\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Penulis blok laporan generik untuk workbook Export Data.
 *
 * Satu "section" = satu blok tabel (judul, meta, header, data, total) yang
 * ditulis mulai dari kolom tertentu. Dengan begitu dua section bisa ditulis
 * berdampingan di satu sheet (dipakai Aging Report & Mutasi Piutang yang
 * menggabungkan ringkasan + detail dalam satu sheet).
 *
 * Bentuk section:
 *   [
 *     'title'   => 'AGING REPORT - RINGKASAN PER KLIEN',
 *     'meta'    => 'Per Tanggal: 2026-07-28 | Total Klien: 12',
 *     'theme'   => ['title' => 'FFB71C1C', 'header' => 'FFC62828', 'band' => 'FFFFEBEE'],
 *     'columns' => [['label' => 'No', 'width' => 5, 'type' => 'int', 'align' => 'center'], ...],
 *     'rows'    => [[1, 'K001', ...], ...],
 *     'total'   => ['', 'TOTAL', 1000, ...]   // opsional
 *   ]
 */
class ExportDataSheetWriter
{
    public const ROW_TITLE      = 1;
    public const ROW_META       = 2;
    public const ROW_SPACER     = 3;
    public const ROW_HEADER     = 4;
    public const ROW_FIRST_DATA = 5;

    /** Lebar kolom pemisah antara dua section yang ditulis berdampingan. */
    public const GAP_WIDTH = 3;

    private const TOTAL_FILL   = 'FFE65100';
    private const TOTAL_BORDER = 'FFBF360C';

    /**
     * Tulis satu section ke worksheet mulai dari $startColumn (1-based).
     *
     * @return int Jumlah kolom yang dipakai section ini.
     */
    public function write(Worksheet $sheet, array $section, int $startColumn = 1): int
    {
        $columns     = $section['columns'];
        $columnCount = count($columns);
        $theme       = $section['theme'];

        $firstCol = Coordinate::stringFromColumnIndex($startColumn);
        $lastCol  = Coordinate::stringFromColumnIndex($startColumn + $columnCount - 1);

        $this->writeTitle($sheet, $section, $firstCol, $lastCol, $theme);
        $this->writeHeader($sheet, $columns, $startColumn, $firstCol, $lastCol, $theme);

        $rowNum = self::ROW_FIRST_DATA;

        if (empty($section['rows'])) {
            $sheet->mergeCells("{$firstCol}{$rowNum}:{$lastCol}{$rowNum}");
            $sheet->setCellValue("{$firstCol}{$rowNum}", 'Tidak ada data');
            $sheet->getStyle("{$firstCol}{$rowNum}")->applyFromArray([
                'font'      => ['italic' => true, 'color' => ['argb' => 'FF9E9E9E']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($rowNum)->setRowHeight(20);

            return $columnCount;
        }

        foreach ($section['rows'] as $row) {
            $this->writeDataRow($sheet, $columns, $row, $rowNum, $startColumn);
            $rowNum++;
        }

        $this->styleDataBlock($sheet, $columns, $startColumn, $firstCol, $lastCol, $rowNum - 1, $theme);

        if (!empty($section['total'])) {
            $this->writeTotalRow($sheet, $columns, $section['total'], $rowNum, $startColumn, $firstCol, $lastCol);
        }

        return $columnCount;
    }

    /** Lebar kolom pemisah antara dua section berdampingan. */
    public function writeGap(Worksheet $sheet, int $columnIndex): void
    {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setWidth(self::GAP_WIDTH);
    }

    private function writeTitle(Worksheet $sheet, array $section, string $firstCol, string $lastCol, array $theme): void
    {
        $sheet->mergeCells("{$firstCol}" . self::ROW_TITLE . ":{$lastCol}" . self::ROW_TITLE);
        $sheet->setCellValue("{$firstCol}" . self::ROW_TITLE, $section['title']);
        $sheet->getStyle("{$firstCol}" . self::ROW_TITLE)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $theme['title']]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(self::ROW_TITLE)->setRowHeight(32);

        $sheet->mergeCells("{$firstCol}" . self::ROW_META . ":{$lastCol}" . self::ROW_META);
        $sheet->setCellValue(
            "{$firstCol}" . self::ROW_META,
            $section['meta'] . '   |   Diekspor: ' . now()->format('d-m-Y H:i')
        );
        $sheet->getStyle("{$firstCol}" . self::ROW_META)->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF455A64']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $theme['band']]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(self::ROW_META)->setRowHeight(18);
        $sheet->getRowDimension(self::ROW_SPACER)->setRowHeight(6);
    }

    private function writeHeader(
        Worksheet $sheet,
        array $columns,
        int $startColumn,
        string $firstCol,
        string $lastCol,
        array $theme,
    ): void {
        foreach ($columns as $offset => $column) {
            $letter = Coordinate::stringFromColumnIndex($startColumn + $offset);
            $sheet->setCellValue("{$letter}" . self::ROW_HEADER, $column['label']);
            $sheet->getColumnDimension($letter)->setWidth($column['width'] ?? 16);
        }

        $sheet->getStyle("{$firstCol}" . self::ROW_HEADER . ":{$lastCol}" . self::ROW_HEADER)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $theme['header']]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'wrapText'   => true,
            ],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => $theme['title']]]],
        ]);
        $sheet->getRowDimension(self::ROW_HEADER)->setRowHeight(24);
    }

    private function writeDataRow(Worksheet $sheet, array $columns, array $row, int $rowNum, int $startColumn): void
    {
        foreach ($columns as $offset => $column) {
            $letter = Coordinate::stringFromColumnIndex($startColumn + $offset);
            $this->writeCell($sheet, "{$letter}{$rowNum}", $row[$offset] ?? null, $column);
        }
    }

    /**
     * Styling area data diterapkan per blok & per kolom, bukan per sel.
     * Pada laporan besar (ribuan baris x puluhan kolom) styling per sel membuat
     * export memakan puluhan detik dan file-nya membengkak, karena tiap sel
     * melahirkan objek style sendiri.
     */
    private function styleDataBlock(
        Worksheet $sheet,
        array $columns,
        int $startColumn,
        string $firstCol,
        string $lastCol,
        int $lastRow,
        array $theme,
    ): void {
        $firstRow = self::ROW_FIRST_DATA;
        $range    = "{$firstCol}{$firstRow}:{$lastCol}{$lastRow}";

        $sheet->getStyle($range)->applyFromArray([
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        // Zebra striping lewat conditional formatting: satu objek style untuk
        // seluruh blok, bukan satu fill per baris.
        //
        // startColor DAN endColor keduanya diisi: pada differential format (dxf)
        // Excel membaca warna solid fill dari bgColor (= endColor), bukan
        // fgColor, sehingga mengisi startColor saja membuat striping tak terlihat.
        $zebra = new Conditional();
        $zebra->setConditionType(Conditional::CONDITION_EXPRESSION);
        $zebra->setConditions(['MOD(ROW(),2)=0']);

        $fill = $zebra->getStyle()->getFill();
        $fill->setFillType(Fill::FILL_SOLID);
        $fill->getStartColor()->setARGB($theme['band']);
        $fill->getEndColor()->setARGB($theme['band']);

        $sheet->getStyle($range)->setConditionalStyles([$zebra]);

        foreach ($columns as $offset => $column) {
            $letter = Coordinate::stringFromColumnIndex($startColumn + $offset);
            $style  = $sheet->getStyle("{$letter}{$firstRow}:{$letter}{$lastRow}");

            $style->getAlignment()->setHorizontal($this->alignmentFor($column));

            if (in_array($column['type'] ?? 'text', ['int', 'currency'], true)) {
                $style->getNumberFormat()->setFormatCode('#,##0');
            }
        }
    }

    private function writeTotalRow(
        Worksheet $sheet,
        array $columns,
        array $total,
        int $rowNum,
        int $startColumn,
        string $firstCol,
        string $lastCol,
    ): void {
        foreach ($columns as $offset => $column) {
            $letter = Coordinate::stringFromColumnIndex($startColumn + $offset);
            $this->writeCell($sheet, "{$letter}{$rowNum}", $total[$offset] ?? null, $column);
            $sheet->getStyle("{$letter}{$rowNum}")->getAlignment()->setHorizontal($this->alignmentFor($column));

            if (in_array($column['type'] ?? 'text', ['int', 'currency'], true)) {
                $sheet->getStyle("{$letter}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
            }
        }

        $sheet->getStyle("{$firstCol}{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::TOTAL_FILL]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => self::TOTAL_BORDER]]],
        ]);
        $sheet->getRowDimension($rowNum)->setRowHeight(20);
    }

    /** Hanya menulis nilai — styling-nya diterapkan per kolom di styleDataBlock(). */
    private function writeCell(Worksheet $sheet, string $coordinate, mixed $value, array $column): void
    {
        $type = $column['type'] ?? 'text';

        if (in_array($type, ['int', 'currency'], true) && is_numeric($value)) {
            $sheet->getCell($coordinate)->setValueExplicit(
                $type === 'int' ? (int) $value : (float) $value,
                DataType::TYPE_NUMERIC
            );

            return;
        }

        $sheet->getCell($coordinate)->setValueExplicit((string) ($value ?? ''), DataType::TYPE_STRING);
    }

    private function alignmentFor(array $column): string
    {
        return match ($column['align'] ?? null) {
            'center' => Alignment::HORIZONTAL_CENTER,
            'right'  => Alignment::HORIZONTAL_RIGHT,
            'left'   => Alignment::HORIZONTAL_LEFT,
            default  => in_array($column['type'] ?? 'text', ['int', 'currency'], true)
                ? Alignment::HORIZONTAL_RIGHT
                : Alignment::HORIZONTAL_LEFT,
        };
    }
}
