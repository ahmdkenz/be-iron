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
 * Template tab "Import Master Opening Balance" — desain FLAT & AUTO-GROUP (meniru pola
 * Import Master Invoice): 1 baris = 1 invoice historis. `no_urut` adalah KUNCI GRUP
 * (boleh berulang di banyak baris untuk klien yang sama, TIDAK unik per baris) — semua
 * baris dengan no_urut sama digabung jadi 1 Opening Balance. Sheet "Rincian Invoice Asal"
 * yang dulu terpisah SUDAH MELEBUR ke sheet utama "Data Opening Balance".
 *
 * Tanggal Opening Balance (kapan saldo dicatat) TIDAK ada di file — diisi 1x di form
 * upload ("cutover date"), berlaku sama untuk semua baris. tanggal_invoice_asal per
 * baris tetap tanggal invoice historisnya masing-masing.
 *
 * Tersedia 2 format:
 *   - XLSX (PhpSpreadsheet, 3 sheet: Data Opening Balance + Item Invoice Asal [opsional]
 *     + Petunjuk Pengisian) — cocok untuk volume kecil-menengah.
 *   - CSV (native fputcsv, TANPA PhpSpreadsheet) — 1 tabel flat dengan kolom penanda
 *     `tipe_baris` (OB/ITEM). Mendukung volume jauh lebih besar, cocok untuk backfill
 *     data historis dalam jumlah besar (mis. s/d 3 tahun ke belakang).
 *
 * HEADERS/EXAMPLES dipakai sebagai acuan bersama supaya kolomnya tidak pernah desync
 * dengan OpeningBalanceImportService::parseObSheet()/parseCsv().
 */
class OpeningBalanceImportTemplateService
{
    /**
     * Header Sheet 1 "Data Opening Balance" (XLSX) — urutan & nama HARUS identik dengan
     * OpeningBalanceImportService::parseObSheet(). tipe_klien & no_urut sengaja ditaruh
     * PALING KANAN (setelah keterangan) — kolom identitas & rincian invoice diisi
     * berurutan dulu, metadata sistem (tipe_klien/no_urut) menyusul di akhir.
     */
    private const OB_HEADERS = [
        'nama_klien (*)', 'kode_resto', 'nama_resto',
        'no_invoice_asal', 'tanggal_invoice_asal', 'sisa_tagihan_asal (*)', 'deskripsi', 'keterangan',
        'tipe_klien (*)', 'no_urut (*)',
    ];

    private const OB_WIDTHS = [30, 14, 24, 22, 20, 20, 30, 26, 14, 12];

    /**
     * Header gabungan untuk CSV — 1 tabel flat, dibedakan kolom pertama `tipe_baris`
     * (OB/ITEM). Urutan HARUS identik dengan konstanta CSV_COL_* di
     * OpeningBalanceImportService::parseCsv().
     */
    private const CSV_HEADERS = [
        'tipe_baris (*)', 'no_urut (*)', 'nama_klien', 'kode_resto', 'nama_resto', 'tipe_klien',
        'no_invoice_asal', 'tanggal_invoice_asal', 'sisa_tagihan_asal (*)', 'deskripsi', 'keterangan',
        'kode_barang', 'nama_barang', 'qty', 'satuan', 'harga_satuan', 'subtotal',
    ];

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
            ['# 1 file = 1 tabel flat berisi 2 JENIS baris, dibedakan kolom pertama tipe_baris: OB (data Opening Balance, sekaligus rincian invoice asal) dan ITEM (Item Invoice Asal, opsional).'],
            ['# no_urut adalah KUNCI GRUP, BOLEH BERULANG di banyak baris OB untuk klien yang sama — semua baris dengan no_urut sama digabung jadi 1 Opening Balance. nama_klien/tipe_klien HANYA WAJIB diisi di baris PERTAMA tiap no_urut — baris berikutnya boleh dikosongkan.'],
            ['# Aturan per grup: kalau HANYA 1 baris untuk no_urut itu DAN no_invoice_asal dikosongkan -> dianggap saldo lump sum tanpa rincian (saldo_awal = sisa_tagihan_asal baris itu langsung). Selain itu (>1 baris, ATAU 1 baris dengan no_invoice_asal terisi) -> SETIAP baris WAJIB isi no_invoice_asal & tanggal_invoice_asal (masing-masing jadi 1 rincian invoice asal); saldo_awal Opening Balance = SUM sisa_tagihan_asal semua baris grup itu.'],
            ['# tipe_klien wajib diisi PT/B2B atau RESTO/B2C (sinonim, boleh pilih salah satu istilah) di baris PERTAMA tiap grup. PT/B2B = saldo konsolidasi (tanpa resto spesifik, resolve via nama_klien, DITOLAK jika ambigu) — kode_resto OPSIONAL & bebas isi di SETIAP baris kalau invoice historisnya per resto (info tambahan di Rincian, tidak memengaruhi resolusi klien). RESTO/B2C = saldo per outlet — kode_resto WAJIB diisi di baris pertama & divalidasi ke MASTER DATA.'],
            ['# PT/B2B multi-resto: kalau 1 klien PT punya banyak no_urut berbeda (1 per resto asalnya, sebelum konsolidasi), TIDAK masalah — sistem otomatis menggabungkan SEMUA no_urut yang resolve ke klien PT yang sama jadi 1 Opening Balance gabungan, tiap invoice tetap membawa kode_resto/nama_resto asalnya sendiri.'],
            ['# PENTING: tanggal Opening Balance (kapan saldo ini dicatat) TIDAK diisi di file ini — diisi 1x di form upload ("Tanggal Saldo Awal / Cutover"), berlaku sama untuk SEMUA baris OB dalam file ini. tanggal_invoice_asal per baris tetap tanggal invoice historisnya masing-masing, boleh berbeda jauh antar baris.'],
            ['# Baris ITEM: no_urut & no_invoice_asal wajib (harus cocok baris OB terkait) — field lain (kode_barang, nama_barang, qty, satuan, harga_satuan) OPSIONAL, boleh dikosongkan untuk data lampau yang tidak diketahui detail itemnya. kode_barang opsional (dicari juga via nama_barang). subtotal opsional (otomatis = qty x harga_satuan jika kosong).'],
            ['# Format tanggal: DD-MM-YYYY, atau nama bulan Indonesia (mis. 1 Januari 2023). Upload hanya bisa dilakukan oleh role ADMIN, MANAGER, atau SUPERVISOR. Baris [CONTOH] & baris diawali "#" otomatis diabaikan saat import.'],
        ];

        $rows[] = self::CSV_HEADERS;

        // Baris contoh: no_urut=1 (PT/B2B, lump sum 1 baris tanpa rincian), no_urut=2
        // (RESTO/B2C, 2 baris invoice historis digabung — baris kedua identitas kosong
        // karena bukan baris pertama grup) + 1 baris ITEM contoh untuk no_urut=2.
        // no_urut=3 & 4: PT/B2B YANG SAMA tapi kode_resto BEDA (tagihan per resto sebelum
        // konsolidasi) — sistem otomatis GABUNG jadi 1 Opening Balance, masing-masing
        // invoice tetap membawa kode_resto/nama_resto asalnya sendiri di Rincian.
        $rows[] = ['OB', '1', '[CONTOH] Nama Klien PT Konsolidasi', '', '', 'B2B', '', '', '15000000', '', 'Saldo awal per Januari 2023 (PT, tanpa resto)', '', '', '', '', '', ''];
        $rows[] = ['OB', '2', '[CONTOH] Nama Klien Outlet', 'KD-001', 'Nama Resto Contoh', 'B2C', 'INV-LAMA-001', '15-01-2022', '9000000', '[CONTOH] Tagihan pengiriman Januari 2022', '', '', '', '', '', '', ''];
        $rows[] = ['OB', '2', '', '', '', '', 'INV-LAMA-002', '20-02-2022', '6000000', '[CONTOH] Tagihan pengiriman Februari 2022', '', '', '', '', '', '', ''];
        $rows[] = ['ITEM', '2', '', '', '', '', 'INV-LAMA-001', '', '', '', '', 'BRG-001', '[CONTOH] Nama Barang Contoh', '10', 'pcs', '1500000', ''];
        $rows[] = ['OB', '3', '[CONTOH] Nama Klien PT Multi Resto', 'KD-010', 'Resto A', 'B2B', 'SI-A-001', '01-01-2023', '3000000', '[CONTOH] Tagihan dari Resto A', '', '', '', '', '', '', ''];
        $rows[] = ['OB', '4', '[CONTOH] Nama Klien PT Multi Resto', 'KD-020', 'Resto B', 'B2B', 'SI-B-001', '05-01-2023', '2500000', '[CONTOH] Tagihan dari Resto B', '', '', '', '', '', '', ''];

        return $rows;
    }

    /** @return string path file sementara (.xlsx) yang siap di-download */
    public function buildXlsxFile(): string
    {
        $spreadsheet = new Spreadsheet;

        $this->buildObSheet($spreadsheet->getActiveSheet());
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
            '1 baris = 1 invoice historis. no_urut adalah KUNCI GRUP (boleh berulang) — semua baris dengan no_urut sama, ATAU yang resolve ke klien PT yang sama (lintas no_urut), digabung jadi 1 Opening Balance. nama_klien/tipe_klien HANYA wajib di baris PERTAMA tiap no_urut; kode_resto/nama_resto opsional untuk PT/B2B, boleh diisi di tiap baris. 1 baris + no_invoice_asal kosong = saldo lump sum tanpa rincian; selain itu tiap baris wajib no_invoice_asal + tanggal_invoice_asal. Tanggal Opening Balance diisi 1x di form upload (cutover), BUKAN di sini. Lihat sheet "Petunjuk Pengisian".',
            self::OB_HEADERS,
            self::OB_WIDTHS,
            [
                ['[CONTOH] Nama Klien PT Konsolidasi', '', '', '', '', '15000000', '', 'Saldo awal per Januari 2023 (PT, tanpa resto)', 'B2B', '1'],
                ['[CONTOH] Nama Klien Outlet', 'KD-001', 'Nama Resto Contoh', 'INV-LAMA-001', '15-01-2022', '9000000', '[CONTOH] Tagihan pengiriman Januari 2022', '', 'B2C', '2'],
                ['', '', '', 'INV-LAMA-002', '20-02-2022', '6000000', '[CONTOH] Tagihan pengiriman Februari 2022', '', '', '2'],
                ['[CONTOH] Nama Klien PT Multi Resto', 'KD-010', 'Resto A', 'SI-A-001', '01-01-2023', '3000000', '[CONTOH] Tagihan dari Resto A', '', 'B2B', '3'],
                ['[CONTOH] Nama Klien PT Multi Resto', 'KD-020', 'Resto B', 'SI-B-001', '05-01-2023', '2500000', '[CONTOH] Tagihan dari Resto B', '', 'B2B', '4'],
            ],
            'FF1B5E20',
            'FF2E7D32',
            'FFF1F8E9',
        );
    }

    private function buildItemSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Item Invoice Asal');
        $this->buildSheetSkeleton(
            $sheet,
            'ITEM INVOICE ASAL (OPSIONAL)',
            'Opsional — isi HANYA jika ingin merinci barang per invoice asal. no_urut_ob + no_invoice_asal harus cocok dengan baris di sheet "Data Opening Balance". kode_barang opsional (dipakai mencari master barang; jika kosong dicari berdasarkan nama_barang). subtotal opsional (otomatis = qty x harga_satuan jika kosong).',
            self::ITEM_HEADERS,
            self::ITEM_WIDTHS,
            [['2', 'INV-LAMA-001', 'BRG-001', '[CONTOH] Nama Barang Contoh', '10', 'pcs', '1500000', '', '']],
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
            '1. File ini berisi 3 sheet: "Data Opening Balance" (wajib), "Item Invoice Asal" (opsional), dan "Petunjuk Pengisian".',
            '2. Sheet "Data Opening Balance": 1 baris = 1 invoice historis. no_urut adalah KUNCI GRUP dan BOLEH BERULANG di banyak baris — semua baris dengan no_urut sama akan digabung menjadi 1 Opening Balance. Ini persis pola Import Master Invoice: "1 baris = 1 item, baris dengan identitas sama digabung jadi 1 entitas".',
            '3. Identitas klien (nama_klien, kode_resto, nama_resto, tipe_klien) HANYA WAJIB diisi di baris PERTAMA untuk tiap no_urut — baris kedua dst boleh dikosongkan atau diulang, tidak dicek kecocokannya (supaya typo kecil di baris kedua dst tidak menggagalkan baris itu).',
            '4. tipe_klien menerima PT atau B2B (sinonim — klien konsolidasi head office, nama_klien dicari case-insensitive dan DITOLAK jika cocok dengan lebih dari 1 klien PT aktif; kode_resto OPSIONAL & bebas isi di tiap baris kalau invoice historisnya per resto, tidak memengaruhi resolusi klien) maupun RESTO atau B2C (sinonim — klien per outlet, kode_resto WAJIB diisi & dicocokkan ketat ke MASTER DATA).',
            '4b. Kalau 1 klien PT/B2B punya no_urut berbeda-beda per resto asalnya (mis. tagihan per outlet sebelum dikonsolidasi), tidak masalah — sistem otomatis menggabungkan SEMUA no_urut yang resolve ke klien PT yang sama jadi 1 Opening Balance, dengan tiap invoice tetap membawa kode_resto/nama_resto asalnya di Rincian Invoice Asal.',
            '5. Aturan per grup no_urut: kalau HANYA ADA 1 baris untuk no_urut itu DAN no_invoice_asal dikosongkan, baris itu dianggap saldo lump sum tanpa rincian (saldo_awal = sisa_tagihan_asal baris itu). Selain itu (grup >1 baris, ATAU 1 baris dengan no_invoice_asal terisi), SETIAP baris di grup itu WAJIB mengisi no_invoice_asal DAN tanggal_invoice_asal — masing-masing baris menjadi 1 rincian invoice asal, dan saldo_awal Opening Balance = jumlah (SUM) sisa_tagihan_asal seluruh baris di grup itu.',
            '6. PENTING: tanggal Opening Balance (kapan saldo ini dicatat / "cutover") TIDAK diisi di file ini — diisi 1 kali di form upload, berlaku sama untuk SEMUA baris OB dalam file yang diupload. tanggal_invoice_asal per baris tetaplah tanggal invoice historisnya masing-masing (boleh berbeda jauh antar baris — itu wajar, mencerminkan umur piutang).',
            '7. Sheet "Item Invoice Asal" bersifat OPSIONAL — isi hanya kalau ingin merinci barang per invoice historis, dihubungkan lewat no_urut_ob + no_invoice_asal yang harus cocok dengan baris di sheet "Data Opening Balance".',
            '8. Baris duplikat (klien yang sudah punya Opening Balance persis di tanggal cutover yang sama) akan DILEWATI (skip), bukan menimbulkan error fatal — baris/grup lain tetap diproses.',
            '9. Baris/grup yang gagal (klien tidak ditemukan/ambigu, data tidak valid, dsb) TIDAK menggagalkan baris/grup lain — hasil akhir menampilkan rincian baris mana yang gagal dan alasannya.',
            '10. Semua Opening Balance hasil import berstatus DRAFT dan tetap harus disetujui (approve) oleh Manager/Supervisor di halaman Opening Balance — sama seperti input manual.',
            '11. Hapus baris [CONTOH] sebelum upload atau biarkan (sistem otomatis mengabaikan baris berawalan "[CONTOH]" atau "#").',
            '12. Format tanggal: DD-MM-YYYY (mis. 01-01-2023), atau nama bulan Indonesia (mis. 1 Januari 2023).',
            '13. Upload hanya bisa dilakukan oleh role ADMIN, MANAGER, atau SUPERVISOR, lewat halaman "Import Master Data" — tab "Import Master Opening Balance". Tanggal cutover wajib dipilih di form sebelum upload bisa dimulai.',
            '14. Untuk data dalam jumlah sangat besar (mis. backfill saldo historis bertahunan), gunakan Template CSV — mendukung volume jauh lebih besar dari XLSX, dengan kolom tipe_baris (OB/ITEM) menggantikan sheet terpisah. Lihat petunjuk di dalam file CSV-nya.',
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
            ['nama_klien',            'Nama Client AR aktif. WAJIB hanya di baris pertama tiap no_urut. Untuk tipe_klien PT/B2B: dicocokkan case-insensitive, DITOLAK jika ambigu (cocok >1 klien PT). Untuk RESTO/B2C: harus sesuai MASTER DATA outlet tsb.', 'Baris pertama grup', 'Nama Klien Contoh'],
            ['kode_resto',            'RESTO/B2C: WAJIB di baris pertama grup, divalidasi ketat ke MASTER DATA & menentukan resolusi klien. PT/B2B: OPSIONAL & freeform (TIDAK divalidasi, TIDAK memengaruhi resolusi klien) — boleh diisi di SETIAP baris kalau tiap invoice historisnya berasal dari resto berbeda, tersimpan sebagai info asal resto di Rincian Invoice Asal.', 'RESTO/B2C wajib, PT/B2B opsional', 'KD-001'],
            ['nama_resto',            'Nama resto (informasi tambahan, tidak divalidasi ketat). Untuk PT/B2B boleh diisi di setiap baris, mengikuti kode_resto pada baris yang sama.', 'Opsional', 'Nama Resto Contoh'],
            ['no_invoice_asal',       'Nomor invoice historis dari sistem lama (bebas format). Wajib kalau grup >1 baris, atau grup 1 baris tapi ingin dicatat sebagai rincian (bukan lump sum).', 'Kondisional (lihat poin 5)', 'INV-LAMA-001'],
            ['tanggal_invoice_asal',  'Tanggal invoice historis ini diterbitkan (format DD-MM-YYYY atau nama bulan Indonesia) — boleh berbeda jauh antar baris dalam 1 grup. Wajib jika no_invoice_asal diisi.', 'Kondisional (lihat poin 5)', '15-01-2022'],
            ['sisa_tagihan_asal',     'Nominal sisa tagihan baris ini. Untuk grup lump sum (1 baris, no_invoice_asal kosong) = saldo_awal Opening Balance langsung. Untuk grup dengan rincian, dijumlahkan (SUM) jadi saldo_awal Opening Balance.', 'Ya', '15000000'],
            ['deskripsi',             'Deskripsi singkat invoice historis ini (dipakai kalau baris ini jadi rincian).', 'Opsional', 'Tagihan pengiriman Januari 2022'],
            ['keterangan',            'Keterangan tambahan. Untuk grup lump sum, dipakai sebagai keterangan Opening Balance. Untuk grup dengan rincian, dipakai sebagai keterangan per rincian invoice ini.', 'Opsional', '-'],
            ['tipe_klien',            'PT atau B2B (saldo konsolidasi head office, TANPA resto spesifik) — atau RESTO atau B2C (saldo per outlet). Sinonim, bebas pilih salah satu istilah. WAJIB hanya di baris pertama tiap no_urut.', 'Baris pertama grup', 'RESTO / B2C'],
            ['no_urut',               'Nomor urut BEBAS tapi jadi KUNCI GRUP — boleh berulang di banyak baris untuk klien yang sama, semua baris dengan no_urut sama digabung jadi 1 Opening Balance.', 'Ya', '1'],
        ]);
        $row += 12;

        $this->buildColumnDescriptionBlock($sheet, $row, 'DESKRIPSI KOLOM — Sheet "Item Invoice Asal" (opsional)', 'FFE65100', 'FFFFA726', [
            ['no_urut_ob',      'Harus cocok dengan kolom no_urut di sheet Data Opening Balance', 'Ya', '2'],
            ['no_invoice_asal', 'Harus cocok dengan no_invoice_asal di sheet Data Opening Balance', 'Ya', 'INV-LAMA-001'],
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
