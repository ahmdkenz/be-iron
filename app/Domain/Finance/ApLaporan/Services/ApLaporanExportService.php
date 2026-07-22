<?php

namespace App\Domain\Finance\ApLaporan\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ApLaporanExportService
{
    private const ACCENT = 'FF1565C0';
    private const ACCENT_DARK = 'FF0D47A1';
    private const ACCENT_LIGHT = 'FFE3F2FD';

    public function buildHutangVendor(array $report): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Hutang per Vendor');

        $lastCol = 'J';

        $this->writeTitle($sheet, "A1:{$lastCol}1", 'LAPORAN HUTANG PER VENDOR');
        $this->writeSubtitle($sheet, "A2:{$lastCol}2", 'Per Tanggal: ' . $report['as_of_date'] . '   |   Diekspor: ' . now()->format('d-m-Y H:i'));
        $sheet->getRowDimension(3)->setRowHeight(6);

        $cols = [
            'A' => ['No', 5],
            'B' => ['Kode Vendor', 16],
            'C' => ['Nama Vendor', 30],
            'D' => ['PIC AP', 20],
            'E' => ['Entitas', 22],
            'F' => ['Jml Tagihan', 12],
            'G' => ['Total Tagihan', 18],
            'H' => ['Total Pembayaran', 18],
            'I' => ['Sisa Hutang', 18],
            'J' => ['Overdue', 18],
        ];
        $this->writeHeaderRow($sheet, $cols, 4, $lastCol);

        $rowNum = 5;
        foreach ($report['rows'] as $i => $row) {
            $bg = $rowNum % 2 === 0 ? self::ACCENT_LIGHT : 'FFFFFFFF';

            $sheet->getCell("A{$rowNum}")->setValueExplicit($i + 1, DataType::TYPE_NUMERIC);
            $sheet->getCell("B{$rowNum}")->setValueExplicit($row['kode_vendor'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("C{$rowNum}")->setValueExplicit($row['nama_vendor'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("D{$rowNum}")->setValueExplicit($row['pic_ap'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("E{$rowNum}")->setValueExplicit($row['entitas'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("F{$rowNum}")->setValueExplicit((int) ($row['jumlah_tagihan'] ?? 0), DataType::TYPE_NUMERIC);

            foreach (['total_tagihan' => 'G', 'total_pembayaran' => 'H', 'sisa_tagihan' => 'I', 'overdue_amount' => 'J'] as $field => $col) {
                $val = (float) ($row[$field] ?? 0);
                $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val ?: '', $val ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                if ($val) $sheet->getStyle("{$col}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
            }

            $this->applyRowStyle($sheet, "A{$rowNum}:{$lastCol}{$rowNum}", $bg);
            $rowNum++;
        }

        $summary = $report['summary'];
        $sheet->mergeCells("A{$rowNum}:F{$rowNum}");
        $sheet->setCellValue("A{$rowNum}", 'TOTAL');
        foreach (['total_tagihan' => 'G', 'total_pembayaran' => 'H', 'sisa_tagihan' => 'I', 'overdue_amount' => 'J'] as $field => $col) {
            $sheet->getCell("{$col}{$rowNum}")->setValueExplicit((float) ($summary[$field] ?? 0), DataType::TYPE_NUMERIC);
            $sheet->getStyle("{$col}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
        }
        $this->applyTotalRowStyle($sheet, "A{$rowNum}:{$lastCol}{$rowNum}");
        $sheet->freezePane('A5');

        $this->buildHutangVendorDetailSheet($spreadsheet, $report);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function buildHutangVendorDetailSheet(Spreadsheet $spreadsheet, array $report): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Detail Tagihan');

        $lastCol = 'K';

        $this->writeTitle($sheet, "A1:{$lastCol}1", 'DETAIL TAGIHAN HUTANG');
        $this->writeSubtitle($sheet, "A2:{$lastCol}2", 'Per Tanggal: ' . $report['as_of_date'] . '   |   Diekspor: ' . now()->format('d-m-Y H:i'));
        $sheet->getRowDimension(3)->setRowHeight(6);

        $cols = [
            'A' => ['No', 5],
            'B' => ['Kode Vendor', 14],
            'C' => ['Nama Vendor', 26],
            'D' => ['No Tagihan', 20],
            'E' => ['No Invoice Vendor', 20],
            'F' => ['Tgl Tagihan', 13],
            'G' => ['Jatuh Tempo', 13],
            'H' => ['Total Tagihan', 16],
            'I' => ['Total Bayar', 16],
            'J' => ['Sisa Tagihan', 16],
            'K' => ['Status', 14],
        ];
        $this->writeHeaderRow($sheet, $cols, 4, $lastCol);

        $rowNum = 5;
        $no     = 1;
        foreach ($report['rows'] as $vendorRow) {
            foreach (($vendorRow['details'] ?? []) as $d) {
                $bg = $rowNum % 2 === 0 ? self::ACCENT_LIGHT : 'FFFFFFFF';

                $sheet->getCell("A{$rowNum}")->setValueExplicit($no, DataType::TYPE_NUMERIC);
                $sheet->getCell("B{$rowNum}")->setValueExplicit($vendorRow['kode_vendor'] ?? '', DataType::TYPE_STRING);
                $sheet->getCell("C{$rowNum}")->setValueExplicit($vendorRow['nama_vendor'] ?? '', DataType::TYPE_STRING);
                $sheet->getCell("D{$rowNum}")->setValueExplicit($d['no_tagihan'] ?? '', DataType::TYPE_STRING);
                $sheet->getCell("E{$rowNum}")->setValueExplicit($d['no_invoice_vendor'] ?? '', DataType::TYPE_STRING);
                $sheet->getCell("F{$rowNum}")->setValueExplicit($d['tanggal_tagihan'] ?? '', DataType::TYPE_STRING);
                $sheet->getCell("G{$rowNum}")->setValueExplicit($d['tanggal_jatuh_tempo'] ?? '', DataType::TYPE_STRING);

                foreach (['total_tagihan' => 'H', 'total_pembayaran' => 'I', 'sisa_tagihan' => 'J'] as $field => $col) {
                    $val = (float) ($d[$field] ?? 0);
                    $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val ?: '', $val ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                    if ($val) $sheet->getStyle("{$col}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
                }

                $sheet->getCell("K{$rowNum}")->setValueExplicit($d['status'] ?? '', DataType::TYPE_STRING);

                $this->applyRowStyle($sheet, "A{$rowNum}:{$lastCol}{$rowNum}", $bg);
                $rowNum++;
                $no++;
            }
        }

        $sheet->freezePane('A5');
    }

    public function buildAging(array $report): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Aging Hutang');

        $lastCol = 'I';

        $this->writeTitle($sheet, "A1:{$lastCol}1", 'LAPORAN AGING HUTANG');
        $this->writeSubtitle($sheet, "A2:{$lastCol}2", 'Per Tanggal: ' . $report['as_of_date'] . '   |   Diekspor: ' . now()->format('d-m-Y H:i'));
        $sheet->getRowDimension(3)->setRowHeight(6);

        $cols = [
            'A' => ['No', 5],
            'B' => ['Kode Vendor', 16],
            'C' => ['Nama Vendor', 30],
            'D' => ['PIC AP', 20],
            'E' => ['Belum JT', 18],
            'F' => ['1–30 Hari', 18],
            'G' => ['31–60 Hari', 18],
            'H' => ['61–90 Hari', 18],
            'I' => ['>90 Hari', 18],
        ];
        $this->writeHeaderRow($sheet, $cols, 4, $lastCol);

        $rowNum = 5;
        foreach ($report['rows'] as $i => $row) {
            $bg = $rowNum % 2 === 0 ? self::ACCENT_LIGHT : 'FFFFFFFF';

            $sheet->getCell("A{$rowNum}")->setValueExplicit($i + 1, DataType::TYPE_NUMERIC);
            $sheet->getCell("B{$rowNum}")->setValueExplicit($row['kode_vendor'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("C{$rowNum}")->setValueExplicit($row['nama_vendor'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("D{$rowNum}")->setValueExplicit($row['pic_ap'] ?? '', DataType::TYPE_STRING);

            foreach (['current' => 'E', 'hari_1_30' => 'F', 'hari_31_60' => 'G', 'hari_61_90' => 'H', 'hari_91_plus' => 'I'] as $field => $col) {
                $val = (float) ($row[$field] ?? 0);
                $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val ?: '', $val ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                if ($val) $sheet->getStyle("{$col}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
            }

            $this->applyRowStyle($sheet, "A{$rowNum}:{$lastCol}{$rowNum}", $bg);
            $rowNum++;
        }

        $summary = $report['summary'];
        $sheet->mergeCells("A{$rowNum}:D{$rowNum}");
        $sheet->setCellValue("A{$rowNum}", 'TOTAL');
        foreach (['current' => 'E', 'hari_1_30' => 'F', 'hari_31_60' => 'G', 'hari_61_90' => 'H', 'hari_91_plus' => 'I'] as $field => $col) {
            $sheet->getCell("{$col}{$rowNum}")->setValueExplicit((float) ($summary[$field] ?? 0), DataType::TYPE_NUMERIC);
            $sheet->getStyle("{$col}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
        }
        $this->applyTotalRowStyle($sheet, "A{$rowNum}:{$lastCol}{$rowNum}");
        $sheet->freezePane('A5');

        $this->buildAgingDetailSheet($spreadsheet, $report);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function buildAgingDetailSheet(Spreadsheet $spreadsheet, array $report): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Detail Tagihan');

        $lastCol = 'I';

        $this->writeTitle($sheet, "A1:{$lastCol}1", 'DETAIL TAGIHAN AGING');
        $this->writeSubtitle($sheet, "A2:{$lastCol}2", 'Per Tanggal: ' . $report['as_of_date'] . '   |   Diekspor: ' . now()->format('d-m-Y H:i'));
        $sheet->getRowDimension(3)->setRowHeight(6);

        $cols = [
            'A' => ['No', 5],
            'B' => ['Kode Vendor', 14],
            'C' => ['Nama Vendor', 26],
            'D' => ['No Tagihan', 20],
            'E' => ['Tgl Tagihan', 13],
            'F' => ['Jatuh Tempo', 13],
            'G' => ['Hari Terlambat', 13],
            'H' => ['Bucket', 14],
            'I' => ['Sisa Tagihan', 16],
        ];
        $this->writeHeaderRow($sheet, $cols, 4, $lastCol);

        $bucketLabels = [
            'current'      => 'Belum JT',
            'hari_1_30'    => '1–30 Hari',
            'hari_31_60'   => '31–60 Hari',
            'hari_61_90'   => '61–90 Hari',
            'hari_91_plus' => '>90 Hari',
        ];

        $rowNum = 5;
        $no     = 1;
        foreach ($report['rows'] as $vendorRow) {
            foreach (($vendorRow['details'] ?? []) as $d) {
                $bg = $rowNum % 2 === 0 ? self::ACCENT_LIGHT : 'FFFFFFFF';

                $sheet->getCell("A{$rowNum}")->setValueExplicit($no, DataType::TYPE_NUMERIC);
                $sheet->getCell("B{$rowNum}")->setValueExplicit($vendorRow['kode_vendor'] ?? '', DataType::TYPE_STRING);
                $sheet->getCell("C{$rowNum}")->setValueExplicit($vendorRow['nama_vendor'] ?? '', DataType::TYPE_STRING);
                $sheet->getCell("D{$rowNum}")->setValueExplicit($d['no_tagihan'] ?? '', DataType::TYPE_STRING);
                $sheet->getCell("E{$rowNum}")->setValueExplicit($d['tanggal_tagihan'] ?? '', DataType::TYPE_STRING);
                $sheet->getCell("F{$rowNum}")->setValueExplicit($d['tanggal_jatuh_tempo'] ?? '', DataType::TYPE_STRING);
                $sheet->getCell("G{$rowNum}")->setValueExplicit((int) ($d['hari_terlambat'] ?? 0), DataType::TYPE_NUMERIC);
                $sheet->getCell("H{$rowNum}")->setValueExplicit($bucketLabels[$d['bucket'] ?? ''] ?? ($d['bucket'] ?? ''), DataType::TYPE_STRING);

                $val = (float) ($d['sisa_tagihan'] ?? 0);
                $sheet->getCell("I{$rowNum}")->setValueExplicit($val ?: '', $val ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                if ($val) $sheet->getStyle("I{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');

                $this->applyRowStyle($sheet, "A{$rowNum}:{$lastCol}{$rowNum}", $bg);
                $rowNum++;
                $no++;
            }
        }

        $sheet->freezePane('A5');
    }

    public function buildHistoriPembayaran(array $report): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Histori Pembayaran');

        $lastCol = 'J';

        $this->writeTitle($sheet, "A1:{$lastCol}1", 'HISTORI PEMBAYARAN AP');

        $summaryText = sprintf(
            'Total: Rp %s  |  Transfer: Rp %s  |  Cash: Rp %s  |  Giro: Rp %s  |  Transaksi: %d',
            number_format($report['summary']['total'], 0, ',', '.'),
            number_format($report['summary']['transfer'], 0, ',', '.'),
            number_format($report['summary']['cash'], 0, ',', '.'),
            number_format($report['summary']['giro'], 0, ',', '.'),
            $report['summary']['jumlah_transaksi']
        );
        $this->writeSubtitle($sheet, "A2:{$lastCol}2", $summaryText, true);

        $periodeText = 'Periode: ' . ($report['tanggal_dari'] ?? '-') . ' s/d ' . ($report['tanggal_sampai'] ?? '-')
            . '   |   Diekspor: ' . now()->format('d-m-Y H:i');
        $this->writeSubtitle($sheet, "A3:{$lastCol}3", $periodeText);
        $sheet->getRowDimension(4)->setRowHeight(6);

        $cols = [
            'A' => ['No', 5],
            'B' => ['Tanggal Bayar', 14],
            'C' => ['No Voucher', 22],
            'D' => ['Vendor', 30],
            'E' => ['No Tagihan', 20],
            'F' => ['PIC AP', 20],
            'G' => ['Entitas', 16],
            'H' => ['Metode', 12],
            'I' => ['Kategori', 12],
            'J' => ['Jumlah Dialokasikan', 18],
        ];
        $this->writeHeaderRow($sheet, $cols, 5, $lastCol);

        $rowNum = 6;
        foreach ($report['rows'] as $i => $r) {
            $bg = $rowNum % 2 === 0 ? self::ACCENT_LIGHT : 'FFFFFFFF';

            $sheet->getCell("A{$rowNum}")->setValueExplicit($i + 1, DataType::TYPE_NUMERIC);
            $sheet->getCell("B{$rowNum}")->setValueExplicit($r['tanggal_pembayaran'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("C{$rowNum}")->setValueExplicit($r['no_referensi'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("D{$rowNum}")->setValueExplicit($r['nama_vendor'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("E{$rowNum}")->setValueExplicit($r['no_tagihan'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("F{$rowNum}")->setValueExplicit($r['pic_ap'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("G{$rowNum}")->setValueExplicit($r['perusahaan'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("H{$rowNum}")->setValueExplicit($r['metode_pembayaran'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("I{$rowNum}")->setValueExplicit($r['kategori_voucher'] ?? '', DataType::TYPE_STRING);
            $sheet->getCell("J{$rowNum}")->setValueExplicit((float) ($r['jumlah_dialokasikan'] ?? 0), DataType::TYPE_NUMERIC);
            $sheet->getStyle("J{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');

            $this->applyRowStyle($sheet, "A{$rowNum}:{$lastCol}{$rowNum}", $bg);
            $rowNum++;
        }

        $sheet->mergeCells("A{$rowNum}:I{$rowNum}");
        $sheet->setCellValue("A{$rowNum}", 'TOTAL');
        $sheet->getCell("J{$rowNum}")->setValueExplicit((float) $report['summary']['total'], DataType::TYPE_NUMERIC);
        $sheet->getStyle("J{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
        $this->applyTotalRowStyle($sheet, "A{$rowNum}:{$lastCol}{$rowNum}");

        $sheet->freezePane('A6');

        return $spreadsheet;
    }

    private function writeTitle(Worksheet $sheet, string $range, string $text): void
    {
        $sheet->mergeCells($range);
        [$cell] = explode(':', $range);
        $sheet->setCellValue($cell, $text);
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::ACCENT]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);
    }

    private function writeSubtitle(Worksheet $sheet, string $range, string $text, bool $bold = false): void
    {
        $sheet->mergeCells($range);
        [$cell] = explode(':', $range);
        $sheet->setCellValue($cell, $text);
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['italic' => !$bold, 'bold' => $bold, 'size' => 9, 'color' => ['argb' => $bold ? self::ACCENT_DARK : 'FF455A64']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::ACCENT_LIGHT]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
    }

    private function writeHeaderRow(Worksheet $sheet, array $cols, int $row, string $lastCol): void
    {
        foreach ($cols as $col => [$label, $width]) {
            $sheet->setCellValue("{$col}{$row}", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::ACCENT]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => self::ACCENT_DARK]]],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
    }

    private function applyRowStyle(Worksheet $sheet, string $range, string $bg): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
    }

    private function applyTotalRowStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => self::ACCENT_DARK]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::ACCENT_LIGHT]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => self::ACCENT]]],
        ]);
    }
}
