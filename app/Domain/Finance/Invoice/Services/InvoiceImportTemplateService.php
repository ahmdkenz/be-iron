<?php

namespace App\Domain\Finance\Invoice\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Template tab "Import Master Invoice" — tersedia 2 format:
 *   - CSV (native fputcsv, TANPA PhpSpreadsheet) — cocok untuk volume besar s/d ~100.000 baris.
 *   - XLSX (PhpSpreadsheet, 2 sheet: MASTER INVOICE + Petunjuk Pengisian) — cocok untuk
 *     volume lebih kecil s/d ~13.000 baris, dengan instruksi lengkap dalam 1 file.
 *
 * HEADERS/EXAMPLES dipakai bersama oleh kedua format supaya kolomnya tidak pernah desync.
 * Isi sheet "Petunjuk Pengisian" ditulis manual (bukan hasil derive dari INSTRUCTIONS milik
 * CSV) — kalau aturan bisnis berubah, keduanya perlu disinkronkan manual saat review.
 */
class InvoiceImportTemplateService
{
    /** Header — urutan & nama HARUS identik dengan kolom yang dibaca InvoiceImportService::parse(). */
    private const HEADERS = [
        'nama_klien (*)', 'tanggal_invoice (*)', 'tanggal_jatuh_tempo', 'no_surat_jalan',
        'keterangan_invoice', 'no_invoice_resto', 'kode_resto (*)', 'nama_resto',
        'kode_barang', 'nama_barang (*)', 'qty (*)', 'satuan', 'harga_satuan (*)', 'tipe_invoice (*)',
    ];

    /** Lebar kolom xlsx, sejajar urutan HEADERS. */
    private const COLUMN_WIDTHS = [28, 20, 18, 18, 26, 20, 16, 26, 16, 30, 12, 10, 18, 16];

    /**
     * Baris instruksi — diawali "#" supaya otomatis dilewati parser (lihat
     * InvoiceImportService::parse(), skip baris berawalan "#"). Menggantikan
     * sheet "Petunjuk Pengisian" terpisah yang tidak bisa ada di CSV (1 file = 1 tabel).
     */
    private const INSTRUCTIONS = [
        '# TEMPLATE IMPORT MASTER INVOICE (B2B & B2C)',
        '# 1 baris = 1 item invoice. Baris dengan tipe_invoice + klien (outlet) + tanggal_invoice yang sama digabung menjadi 1 invoice.',
        '# Kolom bertanda (*) wajib diisi di SETIAP baris, termasuk kode_resto.',
        '# kode_resto wajib diisi di setiap baris (B2B maupun B2C) - dipakai memvalidasi baris terhadap MASTER DATA & menentukan outlet tujuan.',
        '# tipe_invoice wajib konsisten dengan segmen outlet di MASTER DATA: outlet PT -> B2B, outlet RESTO -> B2C. Kalau tidak cocok, baris DITOLAK.',
        '# nama_klien wajib sama persis dengan nama Client AR aktif untuk kode_resto tersebut. Format tanggal: DD-MM-YYYY. qty harus lebih dari 0.',
        '# PENTING: import ini TIDAK menimpa invoice yang sudah dibayar / sudah cocok rekening koran / periodenya terkunci - perubahan seperti itu ditampilkan sebagai kandidat Credit Note / Debit Note untuk ditinjau dulu.',
        '# Upload hanya bisa dilakukan oleh role ADMIN, MANAGER, atau SUPERVISOR. Baris [CONTOH] & baris diawali "#" otomatis diabaikan saat import.',
    ];

    /** Baris contoh — 2 item B2C (1 invoice) + 1 item B2B, sama seperti template lama. */
    private const EXAMPLES = [
        ['Nama Klien B2C', '01-06-2026', '30-06-2026', 'SJ-001', 'Keterangan invoice', '', 'KD-001', 'Nama Resto', 'BRG-001', 'Nama Barang A', '10', 'pcs', '50000', '[CONTOH] B2C'],
        ['Nama Klien B2C', '01-06-2026', '30-06-2026', 'SJ-001', 'Keterangan invoice', '', 'KD-001', 'Nama Resto', 'BRG-002', 'Nama Barang B', '5', 'kg', '20000', '[CONTOH] B2C'],
        ['Nama Klien B2B', '01-06-2026', '30-06-2026', '', '', 'SI-RESTO-001', 'KD-RESTO-01', 'Nama Resto Asal', 'BRG-001', 'Nama Barang A', '20', 'pcs', '50000', '[CONTOH] B2B'],
    ];

    /** @return array<int, array<int, string>> Baris-baris siap ditulis via fputcsv, urut dari atas. */
    public function buildRows(): array
    {
        $rows = [];

        foreach (self::INSTRUCTIONS as $line) {
            $rows[] = [$line];
        }

        $rows[] = self::HEADERS;

        foreach (self::EXAMPLES as $example) {
            $rows[] = $example;
        }

        return $rows;
    }

    // ══════════════════════════════════════════════════════════════
    //  XLSX — 2 sheet: MASTER INVOICE + Petunjuk Pengisian
    // ══════════════════════════════════════════════════════════════

    /** @return string path file sementara (.xlsx) yang siap di-download */
    public function buildXlsxFile(): string
    {
        $spreadsheet = new Spreadsheet();

        $this->buildInvoiceSheet($spreadsheet->getActiveSheet());
        $this->buildInstructionSheet($spreadsheet->createSheet());

        $spreadsheet->setActiveSheetIndex(0);

        $temp = tempnam(sys_get_temp_dir(), 'tpl_invoice_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return $temp;
    }

    private function buildInvoiceSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('MASTER INVOICE');

        $colLetters = array_map(
            fn($i) => Coordinate::stringFromColumnIndex($i + 1),
            array_keys(self::HEADERS),
        );
        $lastCol = end($colLetters);

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'TEMPLATE IMPORT MASTER INVOICE (B2B & B2C)');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF6A1B9A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', '1 baris = 1 item invoice. Baris dengan tipe_invoice + klien (outlet) + tanggal_invoice yang sama digabung menjadi 1 invoice. Kolom bertanda (*) wajib diisi di SETIAP baris, termasuk kode_resto. PENTING: import ini TIDAK menimpa invoice yang sudah dibayar / sudah cocok rekening koran / periodenya terkunci — perubahan seperti itu ditampilkan sebagai kandidat Credit Note / Debit Note untuk ditinjau dulu. Lihat sheet "Petunjuk Pengisian".');
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF37474F']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF3E5F5']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(38);

        $sheet->getRowDimension(3)->setRowHeight(8);

        foreach ($colLetters as $i => $col) {
            $sheet->setCellValue("{$col}4", self::HEADERS[$i]);
            $sheet->getColumnDimension($col)->setWidth(self::COLUMN_WIDTHS[$i]);
        }
        $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF7B1FA2']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF4A148C']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(24);

        // Baris contoh — sumber tunggal self::EXAMPLES (sama dengan yang dipakai template CSV).
        foreach (self::EXAMPLES as $idx => $values) {
            $rowNum = 5 + $idx;
            $isB2b  = str_contains((string) end($values), 'B2B');

            foreach ($colLetters as $i => $col) {
                $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($values[$i], DataType::TYPE_STRING);
            }
            $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray($isB2b ? [
                'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF1A237E']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8EAF6']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFC5CAE9']]],
            ] : [
                'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FFE65100']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF9C4']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFFECB3']]],
            ]);
            $sheet->getRowDimension($rowNum)->setRowHeight(20);
        }

        $lastExampleRow = 4 + count(self::EXAMPLES);
        for ($row = $lastExampleRow + 1; $row <= $lastExampleRow + 60; $row++) {
            $bg = $row % 2 === 0 ? 'FFF5F5F5' : 'FFFFFFFF';
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFE0E0E0']]],
            ]);
            // Kolom tanggal_invoice, tanggal_jatuh_tempo, qty, harga_satuan — format teks
            // supaya Excel tidak otomatis mengubah "01-06-2026" jadi serial tanggal
            // (importDate() mendukung serial number sebagai fallback, tapi teks tetap
            // yang paling dapat diprediksi user awam).
            foreach (['B', 'C', 'K', 'M'] as $col) {
                $sheet->getStyle("{$col}{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
            $sheet->getRowDimension($row)->setRowHeight(18);
        }

        $sheet->freezePane('A5');
        $sheet->setAutoFilter("A4:{$lastCol}4");
    }

    private function buildInstructionSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Petunjuk Pengisian');
        $sheet->getColumnDimension('A')->setWidth(24);
        $sheet->getColumnDimension('B')->setWidth(52);
        $sheet->getColumnDimension('C')->setWidth(16);
        $sheet->getColumnDimension('D')->setWidth(32);

        $row = 1;

        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", 'PETUNJUK PENGISIAN — TEMPLATE IMPORT MASTER INVOICE');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF6A1B9A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(34);
        $row += 2;

        $row = $this->writeSection($sheet, $row, '  ALUR IMPORT', 'FF7B1FA2', [
            '1. Pastikan MASTER DATA (Investor + Resto + Client AR) & MASTER BARANG sudah diimport lebih dulu lewat tab "Import Master Data".',
            '2. Upload file ini di tab "Import Master Invoice". Sistem hanya MEMBACA & MENGKLASIFIKASI dulu — belum ada satu pun invoice yang ditulis.',
            '3. Sistem memberi label per invoice: DIBUAT BARU, UPDATE AMAN, TANPA PERUBAHAN, BUTUH REVIEW, atau DITOLAK.',
            '4. Tekan "Proses Data Aman" — hanya invoice DIBUAT BARU & UPDATE AMAN yang ditulis ke sistem.',
            '5. Invoice BUTUH REVIEW tampil di tabel review. Anda memilih: ajukan sebagai Credit Note / Debit Note, atau abaikan (invoice lama dipertahankan).',
            '6. Credit Note / Debit Note masuk ke alur persetujuan koreksi Ending Balance. Sisa tagihan AR baru berubah SETELAH koreksi disetujui.',
        ]);

        $row++;
        $row = $this->writeSection($sheet, $row, '  KAPAN INVOICE MASUK "BUTUH REVIEW"', 'FFC62828', [
            'Invoice yang sudah tersentuh transaksi TIDAK PERNAH ditimpa otomatis oleh import. Pemicunya:',
            '• Sudah menerima pembayaran (sebagian maupun lunas).',
            '• Pembayarannya sudah punya no. referensi.',
            '• Sudah dicocokkan dengan baris rekening koran.',
            '• Sudah punya penyesuaian Credit Note / Debit Note sebelumnya.',
            '• Masih ada koreksi yang menunggu persetujuan.',
            '• Periodenya terkunci di Ending Balance.',
            'Nominal turun → kandidat Credit Note. Nominal naik → kandidat Debit Note. Nominal sama tapi detail berubah → tinjau manual (tidak bisa jadi CN/DN).',
            'Kalau pembayaran yang sudah masuk jadi lebih besar dari tagihan baru, sistem hanya memberi tanda — pemilihan PDM/alokasi lanjutan tetap keputusan Finance.',
        ]);

        $row++;
        $row = $this->writeSection($sheet, $row, '  ATURAN PENGISIAN', 'FF7B1FA2', [
            '1. 1 baris = 1 item. Baris dengan tipe_invoice + klien (outlet) + tanggal_invoice sama digabung jadi 1 invoice.',
            '2. 1 klien (1 outlet) hanya boleh punya 1 invoice per hari.',
            '3. kode_resto WAJIB diisi di setiap baris (B2B maupun B2C) — dipakai memvalidasi baris terhadap MASTER DATA & menentukan outlet tujuan.',
            '4. tipe_invoice wajib konsisten dengan segmen outlet di MASTER DATA: outlet PT → B2B, outlet RESTO → B2C. Kalau tidak cocok, baris DITOLAK.',
            '5. nama_klien wajib sama persis dengan nama Client AR aktif untuk kode_resto tersebut.',
            '6. Format tanggal: DD-MM-YYYY. qty harus > 0.',
            '7. Baris [CONTOH] dan baris diawali "#" otomatis diabaikan.',
            '8. Upload hanya bisa dilakukan oleh role ADMIN, MANAGER, atau SUPERVISOR.',
        ]);

        $row += 2;

        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", '  DESKRIPSI KOLOM');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF7B1FA2']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++;

        foreach (['A' => 'Kolom', 'B' => 'Keterangan', 'C' => 'Wajib', 'D' => 'Contoh'] as $col => $label) {
            $sheet->setCellValue("{$col}{$row}", $label);
        }
        $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFAB47BC']],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(20);
        $row++;

        $invoiceCols = [
            ['nama_klien',          'Nama Client AR aktif yang sudah ada di sistem. Harus sama dengan MASTER DATA untuk kode_resto ini.', 'Ya',       'PT Maju Bersama'],
            ['tanggal_invoice',     'Tanggal invoice (DD-MM-YYYY). 1 klien hanya boleh punya 1 invoice per hari.',                         'Ya',       '01-06-2026'],
            ['tanggal_jatuh_tempo', 'Tanggal jatuh tempo pembayaran (DD-MM-YYYY).',                                                        'Opsional', '30-06-2026'],
            ['no_surat_jalan',      'Nomor surat jalan.',                                                                                  'Opsional', 'SJ-001'],
            ['keterangan_invoice',  'Keterangan invoice.',                                                                                 'Opsional', 'Pengiriman minggu 1'],
            ['no_invoice_resto',    'Nomor invoice/referensi asal dari resto. Wajib untuk B2B; opsional untuk B2C.',                        'B2B',      'SI-RESTO-0001'],
            ['kode_resto',          'Kode resto asal — WAJIB di setiap baris. Acuan validasi terhadap MASTER DATA & penentu outlet tujuan.', 'Ya',      'KD-001'],
            ['nama_resto',          'Nama resto asal. Wajib untuk B2B; opsional untuk B2C.',                                                'B2B',      'Warung Makan Enak'],
            ['kode_barang',         'Kode barang (lookup ke Master Barang). Jika tidak ditemukan, nama_barang tetap disimpan.',             'Opsional', 'BRG-001'],
            ['nama_barang',         'Nama barang/item yang ditagihkan.',                                                                    'Ya',       'Produk A'],
            ['qty',                 'Jumlah/kuantitas item. Harus lebih dari 0.',                                                           'Ya',       '10'],
            ['satuan',              'Satuan item (pcs, kg, lusin, dll).',                                                                   'Opsional', 'pcs'],
            ['harga_satuan',        'Harga per satuan item.',                                                                               'Ya',       '50000'],
            ['tipe_invoice',        'B2C (outlet RESTO) atau B2B (outlet PT/konsolidasi). Wajib cocok dengan segmen di MASTER DATA.',        'Ya',       'B2C'],
        ];

        foreach ($invoiceCols as $i => [$col, $desc, $req, $ex]) {
            $bg = $i % 2 === 0 ? 'FFFFFFFF' : 'FFF5F5F5';
            $sheet->setCellValue("A{$row}", $col);
            $sheet->setCellValue("B{$row}", $desc);
            $sheet->setCellValue("C{$row}", $req);
            $sheet->setCellValue("D{$row}", $ex);
            $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
                'font'      => ['size' => 9],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(18);
            $row++;
        }
    }

    /** Tulis 1 blok judul + daftar baris teks, kembalikan nomor baris berikutnya. */
    private function writeSection(Worksheet $sheet, int $row, string $title, string $color, array $lines): int
    {
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", $title);
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $color]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++;

        foreach ($lines as $line) {
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->setCellValue("A{$row}", '  ' . $line);
            $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("A{$row}")->getFont()->setSize(9);
            $bg = $row % 2 === 0 ? 'FFF5F5F5' : 'FFFFFFFF';
            $sheet->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bg);
            $sheet->getRowDimension($row)->setRowHeight(18);
            $row++;
        }

        return $row;
    }
}
