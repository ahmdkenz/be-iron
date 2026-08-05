<?php

namespace App\Domain\Finance\OpeningBalance\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Template tab "Import Master Opening Balance" — tersedia 2 format, KEDUANYA mendukung
 * Rincian Invoice Asal & Item Invoice Asal secara OPSIONAL (boleh dikosongkan sepenuhnya
 * untuk OB yang hanya berupa saldo agregat tanpa rincian invoice historis):
 *   - XLSX (PhpSpreadsheet, 4 sheet: Data Opening Balance + Rincian Invoice Asal + Item
 *     Invoice Asal + Petunjuk Pengisian) — 3 sheet terpisah, kapasitas realistis lebih
 *     kecil karena styling & 3 sheet data.
 *   - CSV (native fputcsv, TANPA PhpSpreadsheet) — 1 tabel flat dengan kolom penanda
 *     `tipe_baris` (OB/RINCIAN/ITEM); setiap baris memakai subset kolom header gabungan
 *     yang relevan untuk tipenya. Mendukung volume jauh lebih besar dari XLSX, cocok
 *     untuk backfill data historis dalam jumlah besar (mis. s/d 3 tahun ke belakang).
 *
 * Kolom bertanda (*) pada header wajib diisi HANYA untuk baris/sheet yang bersangkutan —
 * mis. `nama_klien (*)` cuma wajib untuk baris tipe_baris=OB (XLSX: sheet Data Opening
 * Balance), bukan untuk seluruh file/baris lain.
 *
 * Header Sheet 1 XLSX dipakai sebagai acuan bersama supaya kolomnya tidak pernah desync
 * dengan OpeningBalanceImportService::parse().
 */
class OpeningBalanceImportTemplateService
{
    /** Header Sheet 1 "Data Opening Balance" (XLSX) — urutan & nama HARUS identik dengan OpeningBalanceImportService::parse(). */
    private const OB_HEADERS = [
        'nama_klien (*)', 'kode_resto', 'nama_resto', 'tanggal (*)',
        'saldo_awal (*)', 'keterangan', 'tipe_klien (*)', 'no_urut (*)',
    ];

    private const OB_WIDTHS = [30, 14, 26, 14, 18, 32, 14, 12];

    /**
     * Header gabungan untuk CSV — 1 tabel flat, dibedakan kolom pertama `tipe_baris`
     * (OB/RINCIAN/ITEM). Urutan HARUS identik dengan konstanta CSV_COL_* di
     * OpeningBalanceImportService::parseCsv().
     */
    private const CSV_HEADERS = [
        'tipe_baris (*)', 'no_urut (*)', 'nama_klien (*)', 'kode_resto', 'nama_resto',
        'tanggal (*)', 'saldo_awal (*)', 'tipe_klien (*)',
        'no_invoice_asal (*)', 'tanggal_invoice_asal (*)', 'deskripsi', 'jumlah_tagihan_asal', 'sisa_tagihan_asal (*)',
        'kode_barang', 'nama_barang', 'qty', 'satuan', 'harga_satuan', 'subtotal', 'keterangan',
    ];

    private const DETAIL_HEADERS = [
        'no_urut_ob (*)', 'no_invoice_asal (*)', 'tanggal_invoice_asal (*)',
        'deskripsi', 'jumlah_tagihan_asal', 'sisa_tagihan_asal (*)', 'keterangan',
    ];

    private const DETAIL_WIDTHS = [14, 24, 20, 32, 20, 20, 30];

    private const ITEM_HEADERS = [
        'no_urut_ob (*)', 'no_invoice_asal (*)', 'kode_barang', 'nama_barang',
        'qty', 'satuan', 'harga_satuan', 'subtotal', 'keterangan',
    ];

    private const ITEM_WIDTHS = [14, 24, 16, 30, 10, 12, 18, 18, 30];

    /** @return array<int, array<int, string>> Baris CSV siap ditulis via fputcsv — 1 tabel flat, lihat catatan kelas. */
    public function buildRows(): array
    {
        $rows = [
            ['# TEMPLATE IMPORT MASTER OPENING BALANCE (CSV)'],
            ['# 1 file = 1 tabel flat berisi 3 JENIS baris, dibedakan kolom pertama tipe_baris: OB (data Opening Balance), RINCIAN (Rincian Invoice Asal), ITEM (Item Invoice Asal).'],
            ['# Kolom bertanda (*) wajib diisi HANYA untuk baris dengan tipe_baris yang bersangkutan — mis. nama_klien(*) cuma wajib untuk baris tipe_baris=OB, boleh kosong di baris RINCIAN/ITEM.'],
            ['# Baris RINCIAN & ITEM bersifat OPSIONAL secara keseluruhan — kalau OB Anda hanya saldo agregat tanpa rincian invoice historis, cukup jangan sertakan baris RINCIAN/ITEM sama sekali untuk no_urut itu.'],
            ['# Kolom no_urut dipakai ganda: pada baris OB = nomor unik OB tsb; pada baris RINCIAN/ITEM = referensi (no_urut_ob) ke OB terkait.'],
            ['# Baris OB: tipe_klien wajib diisi PT/B2B atau RESTO/B2C (sinonim, boleh pilih salah satu istilah). PT/B2B = saldo konsolidasi (tanpa resto spesifik) — nama_klien wajib cocok Client AR PT aktif secara unik, kode_resto WAJIB DIKOSONGKAN. RESTO/B2C = saldo per outlet — kode_resto WAJIB diisi & divalidasi ke MASTER DATA (harus terdaftar & punya Client AR aktif tipe RESTO). saldo_awal harus > 0 (kecuali diisi lewat baris RINCIAN, lihat baris berikut).'],
            ['# Baris RINCIAN: no_invoice_asal, tanggal_invoice_asal, sisa_tagihan_asal wajib. deskripsi opsional (boleh kosong untuk data lampau yang tidak diketahui deskripsinya). Total sisa_tagihan_asal semua baris RINCIAN untuk 1 no_urut_ob harus SAMA PERSIS dengan saldo_awal OB terkait.'],
            ['# Baris ITEM: no_urut_ob & no_invoice_asal wajib (harus cocok baris RINCIAN terkait) — field lain (kode_barang, nama_barang, qty, satuan, harga_satuan) OPSIONAL, boleh dikosongkan untuk data lampau yang tidak diketahui detail itemnya. kode_barang opsional (dicari juga via nama_barang). subtotal opsional (otomatis = qty x harga_satuan jika kosong).'],
            ['# Format tanggal: DD-MM-YYYY. Upload hanya bisa dilakukan oleh role ADMIN, MANAGER, atau SUPERVISOR. Baris [CONTOH] & baris diawali "#" otomatis diabaikan saat import.'],
        ];

        $rows[] = self::CSV_HEADERS;

        // Baris contoh berantai: 1 OB tipe PT/B2B + 1 OB tipe RESTO/B2C + 1 rincian + 1 item
        // (rincian/item terhubung ke OB no_urut=1) — menunjukkan pola linking yang sama
        // seperti no_urut/no_urut_ob di XLSX, sekaligus contoh kedua tipe_klien.
        $rows[] = ['OB', '1', '[CONTOH] Nama Klien PT Konsolidasi', '', '', '01-01-2023', '15000000', 'B2B', '', '', '', '', '', '', '', '', '', '', '', 'Saldo awal per Januari 2023 (PT, tanpa resto)'];
        $rows[] = ['OB', '2', '[CONTOH] Nama Klien Outlet', 'KD-001', 'Nama Resto Contoh', '01-01-2023', '5000000', 'B2C', '', '', '', '', '', '', '', '', '', '', '', 'Saldo awal per resto'];
        $rows[] = ['RINCIAN', '1', '', '', '', '', '', '', 'INV-LAMA-001', '15-01-2022', '[CONTOH] Tagihan pengiriman Januari 2022', '', '15000000', '', '', '', '', '', '', ''];
        $rows[] = ['ITEM', '1', '', '', '', '', '', '', 'INV-LAMA-001', '', '', '', '', 'BRG-001', '[CONTOH] Nama Barang Contoh', '10', 'pcs', '1500000', '', ''];

        return $rows;
    }

    /** @return string path file sementara (.xlsx) yang siap di-download */
    public function buildXlsxFile(): string
    {
        $spreadsheet = new Spreadsheet;

        $this->buildObSheet($spreadsheet->getActiveSheet());
        $this->buildDetailSheet($spreadsheet->createSheet());
        $this->buildItemSheet($spreadsheet->createSheet());
        $this->buildInstructionSheet($spreadsheet->createSheet());

        $spreadsheet->setActiveSheetIndex(0);

        $temp = tempnam(sys_get_temp_dir(), 'tpl_ob_').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return $temp;
    }

    // ──────────────────────────────────────────────────────────────
    //  XLSX — sheet builders
    // ──────────────────────────────────────────────────────────────

    private function buildObSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Data Opening Balance');
        $this->buildSheetSkeleton(
            $sheet,
            'TEMPLATE IMPORT MASTER OPENING BALANCE',
            'Satu baris = 1 Opening Balance (saldo awal piutang klien). Kolom bertanda (*) wajib diisi. no_urut adalah nomor urut unik per baris — dipakai sebagai referensi oleh sheet "Rincian Invoice Asal" & "Item Invoice Asal" (kolom no_urut_ob). tipe_klien menentukan cara resolusi: PT/B2B (kode_resto WAJIB DIKOSONGKAN, resolve via nama_klien unik) atau RESTO/B2C (kode_resto WAJIB diisi & divalidasi ke MASTER DATA). Lihat sheet "Petunjuk Pengisian".',
            self::OB_HEADERS,
            self::OB_WIDTHS,
            [
                ['[CONTOH] Nama Klien PT Konsolidasi', '', '', '01-01-2023', '15000000', 'Saldo awal per Januari 2023 (PT, tanpa resto)', 'B2B', '1'],
                ['[CONTOH] Nama Klien Outlet', 'KD-001', 'Nama Resto Contoh', '01-01-2023', '5000000', 'Saldo awal per resto', 'B2C', '2'],
            ],
            'FF1B5E20',
            'FF2E7D32',
            'FFF1F8E9',
        );
    }

    private function buildDetailSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Rincian Invoice Asal');
        $this->buildSheetSkeleton(
            $sheet,
            'RINCIAN INVOICE ASAL (OPSIONAL)',
            'Opsional — isi HANYA jika Opening Balance ini punya rincian invoice historis. no_urut_ob harus cocok dengan kolom no_urut di sheet "Data Opening Balance". jumlah_tagihan_asal opsional (otomatis dihitung dari total item bila diisi di sheet "Item Invoice Asal"); sisa_tagihan_asal wajib, dan totalnya (semua baris untuk 1 no_urut_ob) harus sama dengan saldo_awal OB terkait.',
            self::DETAIL_HEADERS,
            self::DETAIL_WIDTHS,
            [['1', 'INV-LAMA-001', '15-01-2022', '[CONTOH] Tagihan pengiriman Januari 2022', '', '15000000', '']],
            'FF6A1B9A',
            'FF8E24AA',
            'FFF3E5F5',
        );
    }

    private function buildItemSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Item Invoice Asal');
        $this->buildSheetSkeleton(
            $sheet,
            'ITEM INVOICE ASAL (OPSIONAL)',
            'Opsional — isi HANYA jika ingin merinci barang per invoice asal. no_urut_ob + no_invoice_asal harus cocok dengan baris di sheet "Rincian Invoice Asal". kode_barang opsional (dipakai mencari master barang; jika kosong dicari berdasarkan nama_barang). subtotal opsional (otomatis = qty x harga_satuan jika kosong).',
            self::ITEM_HEADERS,
            self::ITEM_WIDTHS,
            [['1', 'INV-LAMA-001', 'BRG-001', '[CONTOH] Nama Barang Contoh', '10', 'pcs', '1500000', '', '']],
            'FFE65100',
            'FFEF6C00',
            'FFFFF3E0',
        );
    }

    /**
     * @param  array<int,string>  $headers
     * @param  array<int,int>  $widths
     * @param  array<int,array<int,string>>  $examples  list of example rows
     */
    private function buildSheetSkeleton(
        Worksheet $sheet,
        string $title,
        string $subtitle,
        array $headers,
        array $widths,
        array $examples,
        string $titleColor,
        string $headerColor,
        string $stripeColor,
    ): void {
        $colLetters = array_map(
            fn ($i) => Coordinate::stringFromColumnIndex($i + 1),
            array_keys($headers),
        );
        $lastCol = end($colLetters);

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $titleColor]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', $subtitle);
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF37474F']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $stripeColor]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(34);
        $sheet->getRowDimension(3)->setRowHeight(8);

        foreach ($headers as $i => $name) {
            $col = $colLetters[$i];
            $sheet->setCellValue("{$col}4", $name);
            $sheet->getColumnDimension($col)->setWidth($widths[$i]);
        }
        $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $headerColor]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => $titleColor]]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(24);

        foreach ($examples as $exIdx => $example) {
            $row = 5 + $exIdx;
            foreach ($example as $i => $val) {
                $sheet->getCell("{$colLetters[$i]}{$row}")->setValueExplicit($val, DataType::TYPE_STRING);
            }
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                'font' => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FFE65100']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF9C4']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFFECB3']]],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(20);
        }

        $dataStart = 5 + count($examples);
        for ($row = $dataStart; $row <= $dataStart + 199; $row++) {
            $bg = $row % 2 === 0 ? $stripeColor : 'FFFFFFFF';
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFE0E0E0']]],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(18);
        }

        $sheet->freezePane("A{$dataStart}");
        $sheet->setAutoFilter("A4:{$lastCol}4");
    }

    private function buildInstructionSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Petunjuk Pengisian');
        $sheet->getColumnDimension('A')->setWidth(24);
        $sheet->getColumnDimension('B')->setWidth(52);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(34);

        $row = 1;

        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", 'PETUNJUK PENGISIAN — TEMPLATE IMPORT MASTER OPENING BALANCE');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(34);
        $row += 2;

        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", '  CARA PENGISIAN');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E7D32']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++;

        $steps = [
            '1. File ini berisi 4 sheet: "Data Opening Balance" (wajib), "Rincian Invoice Asal" (opsional), "Item Invoice Asal" (opsional), dan "Petunjuk Pengisian".',
            '2. Sheet "Data Opening Balance": 1 baris = 1 Opening Balance. Kolom bertanda (*) wajib diisi. tipe_klien menerima PT atau B2B (sinonim, sama-sama berarti klien konsolidasi head office — kode_resto WAJIB DIKOSONGKAN, nama_klien dicari case-insensitive dan DITOLAK jika cocok dengan lebih dari 1 klien PT aktif) maupun RESTO atau B2C (sinonim, sama-sama berarti klien per outlet — kode_resto WAJIB diisi, dicocokkan ketat ke MASTER DATA).',
            '2b. PENTING soal tanda (*): kolom bertanda (*) wajib diisi HANYA untuk sheet/baris yang bersangkutan — mis. nama_klien(*) di sheet Data Opening Balance tidak berarti sheet Rincian Invoice Asal & Item Invoice Asal wajib diisi juga. Kedua sheet itu tetap boleh dikosongkan sepenuhnya (lihat poin 4).',
            '3. no_urut adalah nomor urut BEBAS tapi harus UNIK per baris (boleh 1, 2, 3, dst) — dipakai sebagai referensi oleh sheet "Rincian Invoice Asal" (kolom no_urut_ob).',
            '4. Sheet "Rincian Invoice Asal" & "Item Invoice Asal" bersifat OPSIONAL — kosongkan kedua sheet ini jika data Opening Balance Anda hanya berupa saldo agregat tanpa rincian invoice historis per baris (kasus paling umum untuk backfill data lama).',
            '5. Jika diisi: total sisa_tagihan_asal pada semua baris Rincian Invoice Asal untuk 1 no_urut_ob HARUS SAMA PERSIS dengan saldo_awal Opening Balance terkait di sheet "Data Opening Balance".',
            '6. Baris duplikat (klien + tanggal Opening Balance yang sama persis dengan data yang sudah ada di sistem) akan DILEWATI (skip), bukan menimbulkan error fatal — baris lain tetap diproses.',
            '7. Baris yang gagal (klien tidak ditemukan/ambigu, data tidak valid, dsb) TIDAK menggagalkan baris lain — hasil akhir menampilkan rincian baris mana yang gagal dan alasannya.',
            '8. Semua Opening Balance hasil import berstatus DRAFT dan tetap harus disetujui (approve) oleh Manager/Supervisor di halaman Opening Balance — sama seperti input manual.',
            '9. Hapus baris [CONTOH] sebelum upload atau biarkan (sistem otomatis mengabaikan baris berawalan "[CONTOH]" atau "#").',
            '10. Format tanggal: DD-MM-YYYY (mis. 01-01-2023).',
            '11. Upload hanya bisa dilakukan oleh role ADMIN, MANAGER, atau SUPERVISOR, lewat halaman "Import Master Data" — tab "Import Master Opening Balance".',
            '12. Untuk data dalam jumlah sangat besar (mis. backfill saldo historis bertahunan), gunakan Template CSV — mendukung volume jauh lebih besar dari XLSX. Template CSV JUGA mendukung Rincian Invoice Asal & Item secara opsional (lewat kolom tipe_baris: OB/RINCIAN/ITEM dalam 1 tabel) — bukan cuma saldo agregat. Lihat petunjuk di dalam file CSV-nya.',
        ];

        foreach ($steps as $step) {
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->setCellValue("A{$row}", '  '.$step);
            $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("A{$row}")->getFont()->setSize(9);
            $bg = $row % 2 === 0 ? 'FFF5F5F5' : 'FFFFFFFF';
            $sheet->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bg);
            $sheet->getRowDimension($row)->setRowHeight(18);
            $row++;
        }

        $row++;

        $this->buildColumnDescriptionBlock($sheet, $row, 'DESKRIPSI KOLOM — Sheet "Data Opening Balance"', 'FF2E7D32', 'FF66BB6A', [
            ['nama_klien',   'Nama Client AR aktif. Untuk tipe_klien PT/B2B: dicocokkan case-insensitive, DITOLAK jika ambigu (cocok >1 klien PT). Untuk RESTO/B2C: harus sesuai MASTER DATA outlet tsb.', 'Ya', 'Nama Klien Contoh'],
            ['kode_resto',   'Kode resto (tb_resto) — WAJIB untuk tipe_klien RESTO/B2C, WAJIB DIKOSONGKAN untuk PT/B2B. Divalidasi ketat ke MASTER DATA & harus punya Client AR aktif tipe RESTO.', 'RESTO/B2C saja', 'KD-001'],
            ['nama_resto',   'Nama resto (informasi tambahan, tidak divalidasi ketat) — isi untuk baris RESTO/B2C.', 'Opsional', 'Nama Resto Contoh'],
            ['tanggal',      'Tanggal Opening Balance (format DD-MM-YYYY)', 'Ya', '01-01-2023'],
            ['saldo_awal',   'Nominal saldo awal piutang (harus lebih dari 0)', 'Ya', '15000000'],
            ['keterangan',   'Keterangan tambahan', 'Opsional', 'Saldo awal per Januari 2023'],
            ['tipe_klien',   'PT atau B2B (saldo konsolidasi head office, TANPA resto spesifik) — atau RESTO atau B2C (saldo per outlet). Sinonim, bebas pilih salah satu istilah. Menentukan cara pencocokan klien & kewajiban kode_resto.', 'Ya', 'RESTO / B2C'],
            ['no_urut',      'Nomor urut unik per baris — referensi untuk sheet Rincian Invoice Asal (kolom no_urut_ob). Wajib jika Anda mengisi sheet Rincian Invoice Asal.', 'Ya (jika ada rincian)', '1'],
        ]);
        $row += 10;

        $this->buildColumnDescriptionBlock($sheet, $row, 'DESKRIPSI KOLOM — Sheet "Rincian Invoice Asal" (opsional)', 'FF6A1B9A', 'FFAB47BC', [
            ['no_urut_ob',           'Harus cocok dengan kolom no_urut di sheet Data Opening Balance', 'Ya', '1'],
            ['no_invoice_asal',      'Nomor invoice historis dari sistem lama (bebas format)', 'Ya', 'INV-LAMA-001'],
            ['tanggal_invoice_asal', 'Tanggal invoice asal (format DD-MM-YYYY)', 'Ya', '15-01-2022'],
            ['deskripsi',            'Deskripsi singkat invoice asal', 'Opsional', 'Tagihan pengiriman Januari 2022'],
            ['jumlah_tagihan_asal',  'Nominal tagihan awal invoice asal (opsional — otomatis dihitung dari total item jika diisi)', 'Opsional', '15000000'],
            ['sisa_tagihan_asal',    'Sisa tagihan invoice asal — total semua baris untuk 1 no_urut_ob harus = saldo_awal OB terkait', 'Ya', '15000000'],
            ['keterangan',           'Keterangan tambahan', 'Opsional', '-'],
        ]);
        $row += 9;

        $this->buildColumnDescriptionBlock($sheet, $row, 'DESKRIPSI KOLOM — Sheet "Item Invoice Asal" (opsional)', 'FFE65100', 'FFFFA726', [
            ['no_urut_ob',      'Harus cocok dengan kolom no_urut di sheet Data Opening Balance', 'Ya', '1'],
            ['no_invoice_asal', 'Harus cocok dengan no_invoice_asal di sheet Rincian Invoice Asal', 'Ya', 'INV-LAMA-001'],
            ['kode_barang',     'Kode master barang (dicari lebih dulu; jika kosong/tidak ketemu, dicari via nama_barang)', 'Opsional', 'BRG-001'],
            ['nama_barang',     'Nama barang', 'Opsional', 'Nama Barang Contoh'],
            ['qty',             'Kuantitas barang', 'Opsional', '10'],
            ['satuan',          'Satuan barang', 'Opsional', 'pcs'],
            ['harga_satuan',    'Harga per satuan', 'Opsional', '1500000'],
            ['subtotal',        'Subtotal (opsional — otomatis = qty x harga_satuan jika kosong)', 'Opsional', '15000000'],
            ['keterangan',      'Keterangan tambahan', 'Opsional', '-'],
        ]);
    }

    /** @param array<int,array{0:string,1:string,2:string,3:string}> $columns */
    private function buildColumnDescriptionBlock(Worksheet $sheet, int $row, string $title, string $titleColor, string $headerColor, array $columns): void
    {
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", '  '.$title);
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $titleColor]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++;

        foreach (['A' => 'Kolom', 'B' => 'Keterangan', 'C' => 'Wajib', 'D' => 'Contoh'] as $col => $label) {
            $sheet->setCellValue("{$col}{$row}", $label);
        }
        $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $headerColor]],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(20);
        $row++;

        foreach ($columns as $i => [$col, $desc, $req, $ex]) {
            $bg = $i % 2 === 0 ? 'FFFFFFFF' : 'FFF5F5F5';
            $sheet->setCellValue("A{$row}", $col);
            $sheet->setCellValue("B{$row}", $desc);
            $sheet->setCellValue("C{$row}", $req);
            $sheet->setCellValue("D{$row}", $ex);
            $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
                'font' => ['size' => 9],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(18);
            $row++;
        }
    }
}
