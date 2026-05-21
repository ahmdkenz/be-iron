<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BankTemplateGenerator
{
    const SUPPORTED = ['BCA', 'MANDIRI', 'CIMB', 'BSI'];

    const CONFIGS = [
        'BCA' => [
            'label'        => 'BCA',
            'upload_format' => 'CSV',
            'headers'      => ['Tanggal', 'Keterangan', 'No Referensi', 'Cabang', 'Jumlah', 'Saldo'],
            'col_desc'     => [
                'Tanggal'      => ['Tanggal transaksi', 'DD/MM/YYYY', '01/01/2025'],
                'Keterangan'   => ['Deskripsi / uraian transaksi', 'Teks bebas', 'TRANSFER MASUK PT ABC'],
                'No Referensi' => ['Nomor referensi transaksi dari bank (boleh kosong)', 'Teks/angka', 'FT2501010001'],
                'Cabang'       => ['Nama cabang bank (boleh kosong)', 'Teks bebas', 'KCP JAKARTA SELATAN'],
                'Jumlah'       => ['Nominal + " CR" (masuk) atau " DB" (keluar)', 'Angka + CR/DB', '1.500.000,00 CR'],
                'Saldo'        => ['Saldo rekening setelah transaksi', 'Angka desimal', '10.500.000,00'],
            ],
            'special_note' => [
                'Kolom "Jumlah" menggunakan format khusus BCA:',
                '  • Dana MASUK (kredit): tulis angka diikuti spasi dan "CR"   →   contoh: 1.500.000,00 CR',
                '  • Dana KELUAR (debit): tulis angka diikuti spasi dan "DB"   →   contoh:   500.000,00 DB',
            ],
            'upload_note'  => 'File yang diunggah ke sistem harus berformat CSV (.csv) yang diunduh langsung dari BCA e-banking / KlikBCA Bisnis. Template Excel ini hanya digunakan sebagai panduan format data.',
            'sample'       => [
                ['01/01/2025', 'TRANSFER MASUK PT ABC', 'FT2501010001', 'KCP JAKARTA SELATAN', '1.500.000,00 CR', '10.500.000,00'],
                ['02/01/2025', 'BIAYA ADMINISTRASI', '', 'KCP JAKARTA SELATAN', '10.500,00 DB', '10.489.500,00'],
                ['03/01/2025', 'SETORAN TUNAI CV XYZ', 'FT2501030002', 'KCP JAKARTA SELATAN', '2.000.000,00 CR', '12.489.500,00'],
            ],
        ],
        'MANDIRI' => [
            'label'         => 'Bank Mandiri',
            'upload_format' => 'XLSX',
            'headers'       => ['Tanggal Transaksi', 'Deskripsi', 'No Referensi', 'Debet', 'Kredit', 'Saldo'],
            'col_desc'      => [
                'Tanggal Transaksi' => ['Tanggal transaksi', 'DD/MM/YYYY', '01/01/2025'],
                'Deskripsi'         => ['Deskripsi / keterangan transaksi', 'Teks bebas', 'TRANSFER MASUK PT ABC'],
                'No Referensi'      => ['Nomor referensi transaksi dari bank (boleh kosong)', 'Teks/angka', '12345678901234'],
                'Debet'             => ['Nominal dana keluar (kosongkan jika tidak ada)', 'Angka desimal', '10.500,00'],
                'Kredit'            => ['Nominal dana masuk (kosongkan jika tidak ada)', 'Angka desimal', '1.500.000,00'],
                'Saldo'             => ['Saldo rekening setelah transaksi', 'Angka desimal', '10.500.000,00'],
            ],
            'special_note'  => [],
            'upload_note'   => 'File dapat diunggah dalam format XLSX atau XLS yang diunduh langsung dari Mandiri Online Bisnis / Livin\' by Mandiri.',
            'sample'        => [
                ['01/01/2025', 'TRANSFER MASUK PT ABC', '12345678901234', '', '1.500.000,00', '10.500.000,00'],
                ['02/01/2025', 'BIAYA ADM BULANAN', '', '10.500,00', '', '10.489.500,00'],
                ['03/01/2025', 'TRANSFER MASUK CV XYZ', '12345678905678', '', '2.000.000,00', '12.489.500,00'],
            ],
        ],
        'CIMB' => [
            'label'         => 'CIMB Niaga',
            'upload_format' => 'XLSX',
            'headers'       => ['Tanggal Transaksi', 'Keterangan', 'No Referensi', 'Mutasi Debet', 'Mutasi Kredit', 'Saldo Akhir'],
            'col_desc'      => [
                'Tanggal Transaksi' => ['Tanggal transaksi', 'DD/MM/YYYY', '01/01/2025'],
                'Keterangan'        => ['Keterangan / deskripsi transaksi', 'Teks bebas', 'TRANSFER MASUK PT ABC'],
                'No Referensi'      => ['Nomor referensi transaksi dari bank (boleh kosong)', 'Teks/angka', 'CIMB20250101001'],
                'Mutasi Debet'      => ['Nominal dana keluar (kosongkan jika tidak ada)', 'Angka desimal', '10.500,00'],
                'Mutasi Kredit'     => ['Nominal dana masuk (kosongkan jika tidak ada)', 'Angka desimal', '1.500.000,00'],
                'Saldo Akhir'       => ['Saldo rekening setelah transaksi', 'Angka desimal', '10.500.000,00'],
            ],
            'special_note'  => [],
            'upload_note'   => 'File dapat diunggah dalam format XLSX atau XLS yang diunduh langsung dari OCTO Business / CIMB Clicks.',
            'sample'        => [
                ['01/01/2025', 'TRANSFER MASUK PT ABC', 'CIMB20250101001', '', '1.500.000,00', '10.500.000,00'],
                ['02/01/2025', 'BIAYA ADMINISTRASI', '', '10.500,00', '', '10.489.500,00'],
                ['03/01/2025', 'TRANSFER MASUK CV XYZ', 'CIMB20250103002', '', '2.000.000,00', '12.489.500,00'],
            ],
        ],
        'BSI' => [
            'label'         => 'BSI (Bank Syariah Indonesia)',
            'upload_format' => 'XLSX / CSV',
            'headers'       => ['Tanggal', 'Keterangan', 'No Referensi', 'Debit', 'Kredit', 'Saldo'],
            'col_desc'      => [
                'Tanggal'      => ['Tanggal transaksi', 'DD/MM/YYYY', '01/01/2025'],
                'Keterangan'   => ['Keterangan / deskripsi transaksi', 'Teks bebas', 'TRANSFER MASUK PT ABC'],
                'No Referensi' => ['Nomor referensi transaksi dari bank (boleh kosong)', 'Teks/angka', 'BSI20250101001'],
                'Debit'        => ['Nominal dana keluar (kosongkan jika tidak ada)', 'Angka desimal', '10.500,00'],
                'Kredit'       => ['Nominal dana masuk (kosongkan jika tidak ada)', 'Angka desimal', '1.500.000,00'],
                'Saldo'        => ['Saldo rekening setelah transaksi', 'Angka desimal', '10.500.000,00'],
            ],
            'special_note'  => [],
            'upload_note'   => 'File dapat diunggah dalam format XLSX, XLS, atau CSV yang diunduh dari BSI Mobile / BSI Net Banking Bisnis.',
            'sample'        => [
                ['01/01/2025', 'TRANSFER MASUK PT ABC', 'BSI20250101001', '', '1.500.000,00', '10.500.000,00'],
                ['02/01/2025', 'BIAYA ADMINISTRASI', '', '10.500,00', '', '10.489.500,00'],
                ['03/01/2025', 'TRANSFER MASUK CV XYZ', 'BSI20250103002', '', '2.000.000,00', '12.489.500,00'],
            ],
        ],
    ];

    // Palet warna
    const C_HEADER_BG   = '0D47A1'; // biru tua — judul sheet
    const C_SECTION_BG  = '1976D2'; // biru sedang — judul seksi
    const C_TABLE_HEAD  = '1565C0'; // biru — header tabel kolom
    const C_NOTE_BG     = 'FFF8E1'; // kuning muda — catatan khusus
    const C_NOTE_BORDER = 'FFB300'; // kuning tua — border catatan
    const C_WARN_BG     = 'FBE9E7'; // merah muda — peringatan upload
    const C_WARN_BORDER = 'E64A19'; // oranye tua — border peringatan
    const C_ROW_ODD     = 'F5F5F5';
    const C_ROW_EVEN    = 'FFFFFF';
    const C_WHITE       = 'FFFFFF';
    const C_TEXT_DARK   = '212121';
    const C_TEXT_MED    = '546E7A';

    public function generate(string $bankType): BinaryFileResponse
    {
        $bankType = strtoupper($bankType);
        $config   = self::CONFIGS[$bankType]
            ?? throw new \InvalidArgumentException("Bank type tidak didukung: {$bankType}");

        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.');
        }

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle('Template Rekening Koran ' . $config['label'])
            ->setDescription('Template upload rekonsiliasi bank — Sistem IRON');

        $this->buildDataSheet($spreadsheet->getActiveSheet(), $config);
        $this->buildPetunjukSheet($spreadsheet->createSheet(), $config);

        $spreadsheet->setActiveSheetIndex(0);

        $temp     = tempnam(sys_get_temp_dir(), 'tpl_bank_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        $filename = 'template-rekening-koran-' . strtolower($bankType) . '.xlsx';

        return response()
            ->download($temp, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    // ─────────────────────────────────────────────────────────────────
    // SHEET 1 — DATA
    // ─────────────────────────────────────────────────────────────────

    private function buildDataSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $config): void
    {
        $sheet->setTitle('Data');

        $headers  = $config['headers'];
        $colCount = count($headers);
        $lastCol  = $this->col($colCount);

        // ── Baris 1: Judul ──────────────────────────────────────────
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'Template Rekening Koran ' . $config['label'] . ' — Sistem IRON');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => self::C_WHITE]],
            'fill'      => $this->solid(self::C_HEADER_BG),
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        // ── Baris 2: Petunjuk singkat ────────────────────────────────
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', '  Isi data mulai baris 4. Jangan ubah nama kolom di baris 3. Format angka: 1.500.000,00  |  Lihat sheet "Petunjuk" untuk panduan lengkap.');
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 10, 'color' => ['rgb' => '37474F']],
            'fill'      => $this->solid('E3F2FD'),
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(18);

        // ── Baris 3: Header kolom ────────────────────────────────────
        foreach ($headers as $i => $h) {
            $sheet->setCellValue($this->col($i + 1) . '3', $h);
        }
        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => self::C_WHITE]],
            'fill'      => $this->solid(self::C_TABLE_HEAD),
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => $this->border('B0BEC5')],
        ]);
        $sheet->getRowDimension(3)->setRowHeight(20);

        // ── Baris 4+: Contoh data ────────────────────────────────────
        foreach ($config['sample'] as $rowIdx => $row) {
            $r  = $rowIdx + 4;
            $bg = ($rowIdx % 2 === 0) ? self::C_ROW_ODD : self::C_ROW_EVEN;
            foreach ($row as $ci => $val) {
                $sheet->setCellValue($this->col($ci + 1) . $r, $val);
            }
            $sheet->getStyle("A{$r}:{$lastCol}{$r}")->applyFromArray([
                'fill'    => $this->solid($bg),
                'borders' => ['allBorders' => $this->border('E0E0E0')],
                'font'    => ['color' => ['rgb' => '757575'], 'italic' => true],
            ]);
        }

        // Auto-width & freeze
        foreach (range(1, $colCount) as $ci) {
            $sheet->getColumnDimension($this->col($ci))->setAutoSize(true);
        }
        $sheet->freezePane('A4');
    }

    // ─────────────────────────────────────────────────────────────────
    // SHEET 2 — PETUNJUK
    // ─────────────────────────────────────────────────────────────────

    private function buildPetunjukSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $config): void
    {
        $sheet->setTitle('Petunjuk');

        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setWidth(70);
        $sheet->getColumnDimension('C')->setWidth(30);

        $r = 1; // baris aktif

        // ── Judul utama ──────────────────────────────────────────────
        $r = $this->writeMainTitle($sheet, $r, 'PETUNJUK PENGGUNAAN TEMPLATE');
        $r = $this->writeSubtitle($sheet, $r, 'Template Rekening Koran ' . $config['label'] . ' — Sistem IRON');
        $r++;

        // ── Informasi file ───────────────────────────────────────────
        $r = $this->writeSectionHeader($sheet, $r, 'INFORMASI TEMPLATE', 3);
        $r = $this->writeInfoRow($sheet, $r, 'Bank', $config['label']);
        $r = $this->writeInfoRow($sheet, $r, 'Format Upload', $config['upload_format']);
        $r = $this->writeInfoRow($sheet, $r, 'Jumlah Kolom', count($config['headers']) . ' kolom');
        $r++;

        // ── Langkah penggunaan ───────────────────────────────────────
        $r = $this->writeSectionHeader($sheet, $r, 'LANGKAH PENGGUNAAN', 2);
        $steps = [
            'Buka sheet "Data" pada file ini.',
            'Isi data rekening koran dimulai dari baris 4 ke bawah.',
            'Jangan mengubah atau menghapus baris header kolom (baris 3).',
            'Format tanggal: DD/MM/YYYY   →   Contoh: 01/01/2025',
            'Format angka: gunakan titik (.) sebagai pemisah ribuan dan koma (,) sebagai desimal.' . "\n" . '     Contoh benar: 1.500.000,00     |     Contoh salah: 1500000 atau 1,500,000.00',
            'Kolom debit/kredit yang kosong (tidak ada transaksi) dibiarkan kosong, jangan diisi 0.',
            'Baris yang seluruh kolomnya kosong akan diabaikan secara otomatis oleh sistem.',
            'Setelah selesai, simpan file lalu unggah melalui:' . "\n" . '     Menu: Rekonsiliasi Bank → Upload Rekening Koran Bank → Pilih Bank → Pilih File.',
        ];
        foreach ($steps as $i => $step) {
            $r = $this->writeStep($sheet, $r, $i + 1, $step);
        }
        $r++;

        // ── Keterangan kolom ────────────────────────────────────────
        $r = $this->writeSectionHeader($sheet, $r, 'KETERANGAN KOLOM', 3);
        $r = $this->writeTableHeader($sheet, $r, ['Nama Kolom', 'Keterangan', 'Contoh Isi']);
        foreach ($config['col_desc'] as $colName => [$desc, , $example]) {
            $r = $this->writeTableRow($sheet, $r, [$colName, $desc, $example], $r % 2 === 0);
        }
        $r++;

        // ── Catatan khusus (jika ada) ────────────────────────────────
        if (!empty($config['special_note'])) {
            $r = $this->writeSectionHeader($sheet, $r, 'CATATAN KHUSUS FORMAT', 2);
            $r = $this->writeNoteBlock($sheet, $r, $config['special_note'], self::C_NOTE_BG, self::C_NOTE_BORDER);
            $r++;
        }

        // ── Catatan penting ──────────────────────────────────────────
        $r = $this->writeSectionHeader($sheet, $r, 'CATATAN PENTING', 2);
        $notes = [
            'Sistem hanya memproses baris KREDIT (dana masuk) untuk dicocokkan dengan data pembayaran yang ada di sistem.',
            'Baris DEBIT (dana keluar) tetap ditampilkan namun akan otomatis berstatus "DIABAIKAN".',
            'Pastikan tanggal dan nominal transaksi sesuai dengan rekening koran asli dari bank agar proses pencocokan otomatis berjalan akurat.',
            'Jika ada baris yang tidak terbaca oleh sistem, periksa kembali format tanggal (DD/MM/YYYY) dan format angka pada kolom nominal.',
        ];
        foreach ($notes as $note) {
            $r = $this->writeBullet($sheet, $r, $note);
        }
        $r++;

        // ── Catatan upload ───────────────────────────────────────────
        $r = $this->writeSectionHeader($sheet, $r, 'FORMAT FILE UNTUK UPLOAD', 2);
        $r = $this->writeNoteBlock($sheet, $r, [$config['upload_note']], self::C_WARN_BG, self::C_WARN_BORDER);
    }

    // ─────────────────────────────────────────────────────────────────
    // HELPER — TULIS KONTEN SHEET PETUNJUK
    // ─────────────────────────────────────────────────────────────────

    private function writeMainTitle(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, int $r, string $text): int
    {
        $s->mergeCells("A{$r}:C{$r}");
        $s->setCellValue("A{$r}", $text);
        $s->getStyle("A{$r}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['rgb' => self::C_WHITE]],
            'fill'      => $this->solid(self::C_HEADER_BG),
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $s->getRowDimension($r)->setRowHeight(30);
        return $r + 1;
    }

    private function writeSubtitle(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, int $r, string $text): int
    {
        $s->mergeCells("A{$r}:C{$r}");
        $s->setCellValue("A{$r}", $text);
        $s->getStyle("A{$r}")->applyFromArray([
            'font'      => ['italic' => true, 'size' => 10, 'color' => ['rgb' => self::C_WHITE]],
            'fill'      => $this->solid('1A237E'),
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $s->getRowDimension($r)->setRowHeight(18);
        return $r + 1;
    }

    private function writeSectionHeader(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, int $r, string $text, int $cols = 2): int
    {
        $lastCol = $cols >= 3 ? 'C' : 'B';
        $s->mergeCells("A{$r}:{$lastCol}{$r}");
        $s->setCellValue("A{$r}", '  ' . $text);
        $s->getStyle("A{$r}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => self::C_WHITE]],
            'fill'      => $this->solid(self::C_SECTION_BG),
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $s->getRowDimension($r)->setRowHeight(20);
        return $r + 1;
    }

    private function writeInfoRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, int $r, string $label, string $value): int
    {
        $s->setCellValue("A{$r}", $label);
        $s->setCellValue("B{$r}", $value);
        $s->getStyle("A{$r}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => self::C_TEXT_DARK]],
            'fill' => $this->solid('F5F5F5'),
        ]);
        $s->getStyle("B{$r}")->applyFromArray([
            'font' => ['color' => ['rgb' => self::C_TEXT_DARK]],
        ]);
        $s->getStyle("A{$r}:B{$r}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E0E0E0');
        $s->getRowDimension($r)->setRowHeight(18);
        return $r + 1;
    }

    private function writeStep(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, int $r, int $num, string $text): int
    {
        $s->setCellValue("A{$r}", $num . '.');
        $s->setCellValue("B{$r}", $text);
        $s->getStyle("A{$r}")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => self::C_SECTION_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
        ]);
        $s->getStyle("B{$r}")->applyFromArray([
            'font'      => ['color' => ['rgb' => self::C_TEXT_DARK]],
            'alignment' => ['wrapText' => true],
        ]);
        $lineCount = substr_count($text, "\n") + 1;
        $s->getRowDimension($r)->setRowHeight($lineCount > 1 ? 30 : 17);
        return $r + 1;
    }

    private function writeBullet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, int $r, string $text): int
    {
        $s->setCellValue("A{$r}", '•');
        $s->setCellValue("B{$r}", $text);
        $s->getStyle("A{$r}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 12, 'color' => ['rgb' => self::C_SECTION_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $s->getStyle("B{$r}")->applyFromArray([
            'font'      => ['color' => ['rgb' => self::C_TEXT_DARK]],
            'alignment' => ['wrapText' => true],
        ]);
        $s->getRowDimension($r)->setRowHeight(17);
        return $r + 1;
    }

    private function writeTableHeader(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, int $r, array $labels): int
    {
        foreach ($labels as $i => $label) {
            $s->setCellValue($this->col($i + 1) . $r, $label);
        }
        $lastCol = $this->col(count($labels));
        $s->getStyle("A{$r}:{$lastCol}{$r}")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => self::C_WHITE]],
            'fill'      => $this->solid(self::C_TABLE_HEAD),
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders'   => ['allBorders' => $this->border('B0BEC5')],
        ]);
        $s->getRowDimension($r)->setRowHeight(18);
        return $r + 1;
    }

    private function writeTableRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, int $r, array $cells, bool $odd): int
    {
        foreach ($cells as $i => $val) {
            $s->setCellValue($this->col($i + 1) . $r, $val);
        }
        $lastCol = $this->col(count($cells));
        $bg      = $odd ? self::C_ROW_ODD : self::C_ROW_EVEN;
        $s->getStyle("A{$r}:{$lastCol}{$r}")->applyFromArray([
            'fill'    => $this->solid($bg),
            'borders' => ['allBorders' => $this->border('E0E0E0')],
            'font'    => ['color' => ['rgb' => self::C_TEXT_DARK]],
        ]);
        $s->getStyle("A{$r}")->getFont()->setBold(true);
        $s->getRowDimension($r)->setRowHeight(17);
        return $r + 1;
    }

    private function writeNoteBlock(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, int $r, array $lines, string $bgColor, string $borderColor): int
    {
        $startRow = $r;
        foreach ($lines as $line) {
            $s->mergeCells("A{$r}:C{$r}");
            $s->setCellValue("A{$r}", '  ' . $line);
            $s->getStyle("A{$r}")->applyFromArray([
                'font'      => ['color' => ['rgb' => self::C_TEXT_DARK], 'italic' => false],
                'fill'      => $this->solid($bgColor),
                'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $lineCount = substr_count($line, "\n") + 1;
            $s->getRowDimension($r)->setRowHeight($lineCount > 1 ? 30 : 18);
            $r++;
        }
        // Border luar blok
        $s->getStyle("A{$startRow}:C" . ($r - 1))->getBorders()->getOutline()
            ->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB($borderColor);
        return $r;
    }

    // ─────────────────────────────────────────────────────────────────
    // HELPER — STYLE
    // ─────────────────────────────────────────────────────────────────

    private function solid(string $rgb): array
    {
        return ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $rgb]];
    }

    private function border(string $rgb): array
    {
        return ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $rgb]];
    }

    private function col(int $n): string
    {
        $letter = '';
        while ($n > 0) {
            $mod    = ($n - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $n      = (int)(($n - $mod) / 26);
        }
        return $letter;
    }
}
