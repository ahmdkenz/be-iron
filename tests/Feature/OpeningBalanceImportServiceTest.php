<?php

namespace Tests\Feature;

use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\OpeningBalance\Services\OpeningBalanceImportService;
use App\Models\Barang;
use App\Models\KlienAr;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Unit test untuk logic parsing & resolusi di OpeningBalanceImportService.
 * Menggunakan reflection agar private method bisa diakses tanpa mengubah visibility
 * (pola sama seperti MasterImportServiceTest — lihat memory project_test_db_migrate_fresh_broken:
 * migrate:fresh rusak di proyek ini, jadi test murni logic tanpa DB writes).
 */
class OpeningBalanceImportServiceTest extends TestCase
{
    private OpeningBalanceImportService $service;

    private \ReflectionClass $ref;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new OpeningBalanceImportService(
            $this->createMock(InvoiceService::class),
        );

        $this->ref = new \ReflectionClass($this->service);
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $m = $this->ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($this->service, ...$args);
    }

    private function makeKlien(int $id, string $namaKlien, string $tipeKlien): KlienAr
    {
        $k = new KlienAr;
        $k->forceFill(['id' => $id, 'nama_klien' => $namaKlien, 'tipe_klien' => $tipeKlien, 'perusahaan_id' => null]);

        return $k;
    }

    private function barang(int $id, ?string $kodeBarang, string $namaBarang): Barang
    {
        $b = new Barang;
        $b->forceFill(['id' => $id, 'kode_barang' => $kodeBarang, 'nama_barang' => $namaBarang]);

        return $b;
    }

    // ──────────────────────────────────────────────────────────────
    //  validateRowAgainstMasterData
    // ──────────────────────────────────────────────────────────────

    public function test_validasi_pt_meloloskan_kode_resto_kosong(): void
    {
        $this->assertNull($this->service->validateRowAgainstMasterData('PT', null, []));
    }

    public function test_validasi_pt_menolak_kode_resto_terisi(): void
    {
        $error = $this->service->validateRowAgainstMasterData('PT', 'KD-001', []);

        $this->assertStringContainsString('harus dikosongkan', $error);
        $this->assertStringContainsString('PT', $error);
    }

    public function test_validasi_resto_wajib_kode_resto(): void
    {
        $error = $this->service->validateRowAgainstMasterData('RESTO', null, []);

        $this->assertStringContainsString('wajib diisi', $error);
    }

    public function test_validasi_resto_menolak_kode_resto_tidak_ada_di_master_data(): void
    {
        $error = $this->service->validateRowAgainstMasterData('RESTO', 'KD-TIDAK-ADA', []);

        $this->assertStringContainsString('tidak ditemukan', $error);
    }

    public function test_validasi_resto_meloloskan_baris_yang_konsisten(): void
    {
        $map = ['KD-001' => ['tipe_klien' => 'RESTO', 'nama_klien' => 'Nama Outlet', 'klien_id' => 3]];

        $this->assertNull($this->service->validateRowAgainstMasterData('RESTO', 'kd-001', $map));
    }

    /**
     * Regresi padanan FB257/Veteran (Import Invoice) — kalau MASTER DATA sudah
     * menyatakan outlet itu terkonsolidasi ke PT, baris RESTO untuk kode_resto tsb
     * wajib gagal & diarahkan submit ulang sebagai PT, bukan diam-diam di-fallback.
     */
    public function test_validasi_resto_menolak_outlet_yang_sudah_pt(): void
    {
        $map = ['FB257' => ['tipe_klien' => 'PT', 'nama_klien' => 'PT. Arkhan Berkah Bersama', 'klien_id' => 5]];

        $error = $this->service->validateRowAgainstMasterData('RESTO', 'FB257', $map);

        $this->assertNotNull($error);
        $this->assertStringContainsString('FB257', $error);
        $this->assertStringContainsString('PT', $error);
    }

    // ──────────────────────────────────────────────────────────────
    //  resolveKlienForOb
    // ──────────────────────────────────────────────────────────────

    public function test_resolve_pt_via_nama_klien_unik(): void
    {
        $ptNamaGroups = collect([$this->makeKlien(1, 'Nama Klien PT', 'PT')])->groupBy(fn ($k) => strtolower(trim($k->nama_klien)));
        $restoMap = collect();

        $result = $this->invoke('resolveKlienForOb', 'PT', 'nama klien PT', null, $ptNamaGroups, $restoMap);

        $this->assertNotNull($result['klien']);
        $this->assertSame(1, $result['klien']->id);
        $this->assertNull($result['error']);
    }

    public function test_resolve_pt_nama_tidak_ditemukan(): void
    {
        $result = $this->invoke('resolveKlienForOb', 'PT', 'Tidak Ada', null, collect(), collect());

        $this->assertNull($result['klien']);
        $this->assertStringContainsString('tidak ditemukan', $result['error']);
    }

    public function test_resolve_pt_via_nama_ambigu_ditolak(): void
    {
        // Tidak ada kode_klien lagi sebagai disambiguator — nama klien PT wajib unik.
        $ptNamaGroups = collect([
            $this->makeKlien(3, 'Nama Kembar', 'PT'),
            $this->makeKlien(4, 'Nama Kembar', 'PT'),
        ])->groupBy(fn ($k) => strtolower(trim($k->nama_klien)));

        $result = $this->invoke('resolveKlienForOb', 'PT', 'Nama Kembar', null, $ptNamaGroups, collect());

        $this->assertNull($result['klien']);
        $this->assertStringContainsString('2 klien berbeda', $result['error']);
    }

    public function test_resolve_resto_strict_via_kode_resto(): void
    {
        $restoMap = collect(['KD-001' => $this->makeKlien(5, 'Nama Outlet', 'RESTO')]);

        $result = $this->invoke('resolveKlienForOb', 'RESTO', 'Nama Outlet', 'kd-001', collect(), $restoMap);

        $this->assertNotNull($result['klien']);
        $this->assertSame(5, $result['klien']->id);
        $this->assertNull($result['error']);
    }

    public function test_resolve_resto_strict_no_fallback_ke_nama(): void
    {
        // kode_resto tidak ketemu di restoMap — TIDAK boleh fallback mencari via nama,
        // supaya salah ketik kode_resto tidak nyasar ke outlet lain (pola Import Invoice).
        $restoMap = collect(['KD-001' => $this->makeKlien(5, 'Nama Outlet', 'RESTO')]);

        $result = $this->invoke('resolveKlienForOb', 'RESTO', 'Nama Outlet', 'KD-SALAH-KETIK', collect(), $restoMap);

        $this->assertNull($result['klien']);
        $this->assertStringContainsString('KD-SALAH-KETIK', $result['error']);
    }

    // ──────────────────────────────────────────────────────────────
    //  resolveBarangId
    // ──────────────────────────────────────────────────────────────

    public function test_resolve_barang_by_kode_takes_priority(): void
    {
        $barangByKode = collect([$this->barang(10, 'BRG-001', 'Nama Lama')])->keyBy(fn ($b) => strtolower($b->kode_barang));
        $barangByNama = collect([$this->barang(10, 'BRG-001', 'Nama Lama')])->keyBy(fn ($b) => strtolower($b->nama_barang));

        $id = $this->invoke('resolveBarangId', 'BRG-001', 'Nama Yang Beda', $barangByKode, $barangByNama);

        $this->assertSame(10, $id);
    }

    public function test_resolve_barang_falls_back_to_nama_when_kode_empty(): void
    {
        $barangByKode = collect();
        $barangByNama = collect([$this->barang(11, null, 'Nama Barang Contoh')])->keyBy(fn ($b) => strtolower($b->nama_barang));

        $id = $this->invoke('resolveBarangId', null, 'Nama Barang Contoh', $barangByKode, $barangByNama);

        $this->assertSame(11, $id);
    }

    public function test_resolve_barang_falls_back_to_nama_when_kode_not_found(): void
    {
        $barangByKode = collect();
        $barangByNama = collect([$this->barang(12, null, 'Nama Barang Lain')])->keyBy(fn ($b) => strtolower($b->nama_barang));

        $id = $this->invoke('resolveBarangId', 'BRG-TIDAK-ADA', 'Nama Barang Lain', $barangByKode, $barangByNama);

        $this->assertSame(12, $id);
    }

    public function test_resolve_barang_not_found_returns_null(): void
    {
        $id = $this->invoke('resolveBarangId', 'BRG-X', 'Tidak Ada', collect(), collect());

        $this->assertNull($id);
    }

    // ──────────────────────────────────────────────────────────────
    //  importValue / importDate / importNum
    // ──────────────────────────────────────────────────────────────

    public function test_import_value_empty_or_dash_returns_null(): void
    {
        $this->assertNull($this->invoke('importValue', ''));
        $this->assertNull($this->invoke('importValue', '-'));
        $this->assertSame('KLI-001', $this->invoke('importValue', ' KLI-001 '));
    }

    public function test_import_date_from_ddmmyyyy_text(): void
    {
        $this->assertSame('2023-01-01', $this->invoke('importDate', '01-01-2023'));
    }

    public function test_import_date_empty_or_dash_returns_null(): void
    {
        $this->assertNull($this->invoke('importDate', ''));
        $this->assertNull($this->invoke('importDate', '-'));
    }

    public function test_import_num_parses_indonesian_thousand_format(): void
    {
        $this->assertSame(15000000.0, $this->invoke('importNum', '15.000.000'));
        $this->assertSame(15000000.5, $this->invoke('importNum', '15.000.000,50'));
    }

    public function test_import_num_non_numeric_returns_zero(): void
    {
        $this->assertSame(0.0, $this->invoke('importNum', '-'));
        $this->assertSame(0.0, $this->invoke('importNum', ''));
    }

    // ──────────────────────────────────────────────────────────────
    //  normalizeHeaderName
    // ──────────────────────────────────────────────────────────────

    public function test_normalize_header_name_strips_mandatory_marker(): void
    {
        $this->assertSame('kode_klien', $this->invoke('normalizeHeaderName', 'kode_klien'));
        $this->assertSame('nama_klien', $this->invoke('normalizeHeaderName', 'nama_klien (*)'));
        $this->assertSame('no_urut', $this->invoke('normalizeHeaderName', '  No_Urut (*)  '));
    }

    // ──────────────────────────────────────────────────────────────
    //  detectHeaderStart
    // ──────────────────────────────────────────────────────────────

    public function test_detect_header_start_finds_header_after_title_rows(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'TEMPLATE IMPORT MASTER OPENING BALANCE');
        $sheet->setCellValue('A2', 'Subtitle...');
        $sheet->setCellValue('A4', 'nama_klien (*)');
        $sheet->setCellValue('B4', 'kode_resto');
        $sheet->setCellValue('A5', 'Nama Klien Contoh');
        $sheet->setCellValue('B5', 'KD-001');

        $detected = $this->invoke('detectHeaderStart', $sheet, 'nama_klien', 8);

        $this->assertTrue($detected['found']);
        $this->assertSame(5, $detected['dataStart']);
    }

    public function test_detect_header_start_not_found_when_header_missing(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Bukan template sama sekali');

        $detected = $this->invoke('detectHeaderStart', $spreadsheet->getActiveSheet(), 'nama_klien', 8);

        $this->assertFalse($detected['found']);
    }

    // ──────────────────────────────────────────────────────────────
    //  parseCsv — 1 tabel flat dibedakan kolom tipe_baris (OB/RINCIAN/ITEM)
    // ──────────────────────────────────────────────────────────────

    private const CSV_HEADER = 'tipe_baris;no_urut;nama_klien (*);kode_resto;nama_resto;tanggal (*);saldo_awal (*);tipe_klien;no_invoice_asal (*);tanggal_invoice_asal (*);deskripsi;jumlah_tagihan_asal;sisa_tagihan_asal (*);kode_barang;nama_barang;qty;satuan;harga_satuan;subtotal;keterangan';

    private function writeTempCsv(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ob_import_test_').'.csv';
        file_put_contents($path, implode("\n", $lines));

        return $path;
    }

    /** Susun 1 baris CSV dari kolom bernama (indeks sesuai CSV_COL_* di service) — kolom yang tidak diisi otomatis kosong. */
    private function csvRow(array $cols): string
    {
        $order = ['tipe_baris', 'no_urut', 'nama_klien', 'kode_resto', 'nama_resto', 'tanggal', 'saldo_awal', 'tipe_klien', 'no_invoice_asal', 'tanggal_invoice_asal', 'deskripsi', 'jumlah_tagihan_asal', 'sisa_tagihan_asal', 'kode_barang', 'nama_barang', 'qty', 'satuan', 'harga_satuan', 'subtotal', 'keterangan'];

        return implode(';', array_map(fn ($key) => $cols[$key] ?? '', $order));
    }

    public function test_parse_csv_skips_comments_examples_and_blank_rows(): void
    {
        $path = $this->writeTempCsv([
            '# TEMPLATE IMPORT MASTER OPENING BALANCE (CSV)',
            '# beberapa baris instruksi lain',
            self::CSV_HEADER,
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '1', 'tipe_klien' => 'PT', 'nama_klien' => '[CONTOH] Nama Klien Contoh', 'tanggal' => '01-01-2023', 'saldo_awal' => '15000000']),
            ';;;;;;;;;;;;;;;;;;;',
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '2', 'tipe_klien' => 'PT', 'nama_klien' => 'Klien Nyata', 'tanggal' => '15-02-2023', 'saldo_awal' => '5000000', 'keterangan' => 'Keterangan asli']),
        ]);

        try {
            [$obRows, $detailsByOb, $errors] = $this->invoke('parseCsv', $path);

            $this->assertEmpty($errors);
            $this->assertEmpty($detailsByOb, 'Tidak ada baris RINCIAN pada file ini.');
            $this->assertCount(1, $obRows, 'Baris [CONTOH] dan baris kosong harus dilewati.');

            $row = array_values($obRows)[0];
            $this->assertSame('PT', $row['tipe_klien']);
            $this->assertSame('Klien Nyata', $row['nama_klien']);
            $this->assertSame('2023-02-15', $row['tanggal']);
            $this->assertSame(5000000.0, $row['saldo_awal']);
            $this->assertSame('Keterangan asli', $row['keterangan']);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_csv_captures_tipe_klien_dan_kode_resto(): void
    {
        $path = $this->writeTempCsv([
            self::CSV_HEADER,
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '1', 'tipe_klien' => 'RESTO', 'nama_klien' => 'Klien Outlet', 'kode_resto' => 'kd-001', 'nama_resto' => 'Resto Contoh', 'tanggal' => '01-03-2023', 'saldo_awal' => '1000000']),
        ]);

        try {
            [$obRows, , $errors] = $this->invoke('parseCsv', $path);

            $this->assertEmpty($errors);
            $this->assertCount(1, $obRows);
            $row = array_values($obRows)[0];
            $this->assertSame('RESTO', $row['tipe_klien']);
            $this->assertSame('kd-001', $row['kode_resto']);
            $this->assertSame('Resto Contoh', $row['nama_resto']);
            $this->assertSame('Klien Outlet', $row['nama_klien']);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_csv_tipe_klien_invalid_reports_error(): void
    {
        $path = $this->writeTempCsv([
            self::CSV_HEADER,
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '1', 'tipe_klien' => 'BUKAN_VALID', 'nama_klien' => 'Klien X', 'tanggal' => '01-03-2023', 'saldo_awal' => '1000000']),
        ]);

        try {
            [$obRows, , $errors] = $this->invoke('parseCsv', $path);

            $this->assertEmpty($obRows);
            $this->assertCount(1, $errors);
            $this->assertStringContainsString('tipe_klien', $errors[0]['message']);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_csv_tipe_klien_b2b_b2c_diterjemahkan_ke_pt_resto(): void
    {
        $path = $this->writeTempCsv([
            self::CSV_HEADER,
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '1', 'tipe_klien' => 'b2b', 'nama_klien' => 'Klien Konsolidasi', 'tanggal' => '01-03-2023', 'saldo_awal' => '1000000']),
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '2', 'tipe_klien' => 'B2C', 'nama_klien' => 'Klien Outlet', 'kode_resto' => 'KD-002', 'tanggal' => '01-03-2023', 'saldo_awal' => '2000000']),
        ]);

        try {
            [$obRows, , $errors] = $this->invoke('parseCsv', $path);

            $this->assertEmpty($errors);
            $this->assertCount(2, $obRows);
            $this->assertSame('PT', $obRows['1']['tipe_klien']);
            $this->assertSame('RESTO', $obRows['2']['tipe_klien']);
        } finally {
            @unlink($path);
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  normalizeTipeKlien
    // ──────────────────────────────────────────────────────────────

    public function test_normalize_tipe_klien_menerima_sinonim(): void
    {
        $this->assertSame('PT', $this->invoke('normalizeTipeKlien', 'PT'));
        $this->assertSame('PT', $this->invoke('normalizeTipeKlien', 'B2B'));
        $this->assertSame('PT', $this->invoke('normalizeTipeKlien', 'b2b'));
        $this->assertSame('RESTO', $this->invoke('normalizeTipeKlien', 'RESTO'));
        $this->assertSame('RESTO', $this->invoke('normalizeTipeKlien', 'B2C'));
        $this->assertSame('RESTO', $this->invoke('normalizeTipeKlien', ' b2c '));
    }

    public function test_normalize_tipe_klien_menolak_nilai_tak_dikenal(): void
    {
        $this->assertNull($this->invoke('normalizeTipeKlien', 'B2X'));
        $this->assertNull($this->invoke('normalizeTipeKlien', ''));
    }

    public function test_parse_csv_missing_nama_klien_reports_error(): void
    {
        $path = $this->writeTempCsv([
            self::CSV_HEADER,
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '1', 'tipe_klien' => 'PT', 'tanggal' => '01-03-2023', 'saldo_awal' => '1000000']),
        ]);

        try {
            [$obRows, , $errors] = $this->invoke('parseCsv', $path);

            $this->assertEmpty($obRows);
            $this->assertCount(1, $errors);
            $this->assertStringContainsString('nama_klien', $errors[0]['message']);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_csv_header_lama_tanpa_tipe_klien_ditolak_jelas(): void
    {
        // Format ancient (pre kode_resto) — kode_klien, tanpa tipe_klien sama sekali.
        $ancientHeader = 'tipe_baris;no_urut;kode_klien;nama_klien (*);tanggal (*);saldo_awal (*);no_invoice_asal (*);tanggal_invoice_asal (*);deskripsi;jumlah_tagihan_asal;sisa_tagihan_asal (*);kode_barang;nama_barang;qty;satuan;harga_satuan;subtotal;keterangan';
        $path = $this->writeTempCsv([
            $ancientHeader,
            'OB;1;KLI-001;Nama Klien Contoh;01-01-2023;15000000;;;;;;;;;;;;',
        ]);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Download ulang Template CSV');
            $this->invoke('parseCsv', $path);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_csv_header_sesi_sebelumnya_tipe_klien_di_indeks_lama_ditolak_jelas(): void
    {
        // Format dari refactor sesi sebelumnya — tipe_klien ada tapi di indeks 2 (sebelum
        // direorder ke indeks 7 setelah saldo_awal).
        $prevHeader = 'tipe_baris;no_urut;tipe_klien;nama_klien (*);kode_resto;nama_resto;tanggal (*);saldo_awal (*);no_invoice_asal (*);tanggal_invoice_asal (*);deskripsi;jumlah_tagihan_asal;sisa_tagihan_asal (*);kode_barang;nama_barang;qty;satuan;harga_satuan;subtotal;keterangan';
        $path = $this->writeTempCsv([
            $prevHeader,
            'OB;1;RESTO;Nama Klien Contoh;KD-001;Resto Contoh;01-01-2023;15000000;;;;;;;;;;;;',
        ]);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Download ulang Template CSV');
            $this->invoke('parseCsv', $path);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_csv_unknown_tipe_baris_reports_error(): void
    {
        $path = $this->writeTempCsv([
            self::CSV_HEADER,
            $this->csvRow(['tipe_baris' => 'FOO', 'no_urut' => '1']),
        ]);

        try {
            [, , $errors] = $this->invoke('parseCsv', $path);

            $this->assertCount(1, $errors);
            $this->assertStringContainsString('tidak dikenali', $errors[0]['message']);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_csv_rincian_and_item_rows_linked_to_ob_via_no_urut(): void
    {
        $path = $this->writeTempCsv([
            self::CSV_HEADER,
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '1', 'tipe_klien' => 'PT', 'nama_klien' => 'Klien Rincian', 'tanggal' => '01-01-2023', 'saldo_awal' => '2000000']),
            $this->csvRow(['tipe_baris' => 'RINCIAN', 'no_urut' => '1', 'no_invoice_asal' => 'INV-ASAL-001', 'tanggal_invoice_asal' => '15-01-2022', 'deskripsi' => 'Tagihan lama', 'sisa_tagihan_asal' => '2000000']),
            $this->csvRow(['tipe_baris' => 'ITEM', 'no_urut' => '1', 'no_invoice_asal' => 'INV-ASAL-001', 'kode_barang' => 'BRG-001', 'nama_barang' => 'Barang Contoh', 'qty' => '4', 'satuan' => 'pcs', 'harga_satuan' => '500000']),
        ]);

        try {
            [$obRows, $detailsByOb, $errors] = $this->invoke('parseCsv', $path);

            $this->assertEmpty($errors);
            $this->assertCount(1, $obRows);
            $this->assertArrayHasKey('1', $detailsByOb);
            $this->assertCount(1, $detailsByOb['1']);

            $detail = $detailsByOb['1'][0];
            $this->assertSame('INV-ASAL-001', $detail['no_invoice_asal']);
            $this->assertSame('2022-01-15', $detail['tanggal_invoice_asal']);
            $this->assertSame(2000000.0, $detail['sisa_tagihan_asal']);
            $this->assertCount(1, $detail['items']);

            $item = $detail['items'][0];
            $this->assertSame('BRG-001', $item['kode_barang']);
            $this->assertSame('Barang Contoh', $item['nama_barang']);
            $this->assertSame(4.0, $item['qty']);
            $this->assertSame(500000.0, $item['harga_satuan']);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_csv_orphan_rincian_row_reports_error(): void
    {
        $path = $this->writeTempCsv([
            self::CSV_HEADER,
            // no_urut=99 tidak cocok baris OB manapun
            $this->csvRow(['tipe_baris' => 'RINCIAN', 'no_urut' => '99', 'no_invoice_asal' => 'INV-X', 'tanggal_invoice_asal' => '01-01-2022', 'deskripsi' => 'Tagihan yatim', 'sisa_tagihan_asal' => '1000000']),
        ]);

        try {
            [$obRows, $detailsByOb, $errors] = $this->invoke('parseCsv', $path);

            $this->assertEmpty($obRows);
            $this->assertEmpty($detailsByOb, 'Detail orphan harus dibuang, bukan ikut dikembalikan.');
            $this->assertCount(1, $errors);
            $this->assertStringContainsString('99', $errors[0]['message']);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_csv_orphan_item_row_reports_error(): void
    {
        $path = $this->writeTempCsv([
            self::CSV_HEADER,
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '1', 'tipe_klien' => 'PT', 'nama_klien' => 'Klien Item Yatim', 'tanggal' => '01-01-2023', 'saldo_awal' => '1000000']),
            $this->csvRow(['tipe_baris' => 'RINCIAN', 'no_urut' => '1', 'no_invoice_asal' => 'INV-ASAL-001', 'tanggal_invoice_asal' => '01-01-2022', 'deskripsi' => 'Tagihan', 'sisa_tagihan_asal' => '1000000']),
            // no_invoice_asal berbeda dari baris RINCIAN di atas — tidak match
            $this->csvRow(['tipe_baris' => 'ITEM', 'no_urut' => '1', 'no_invoice_asal' => 'INV-BEDA', 'nama_barang' => 'Barang Yatim', 'qty' => '1', 'harga_satuan' => '1000000']),
        ]);

        try {
            [$obRows, $detailsByOb, $errors] = $this->invoke('parseCsv', $path);

            $this->assertCount(1, $obRows);
            $this->assertCount(1, $errors);
            $this->assertStringContainsString('Rincian Invoice Asal', $errors[0]['message']);
            $this->assertEmpty($detailsByOb['1'][0]['items'], 'Item yatim tidak boleh nyasar ke detail yang ada.');
        } finally {
            @unlink($path);
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  parseObSheet (XLSX) — urutan kolom baru: nama_klien, kode_resto, nama_resto,
    //  tanggal, saldo_awal, keterangan, tipe_klien, no_urut
    // ──────────────────────────────────────────────────────────────

    public function test_parse_ob_sheet_membaca_urutan_kolom_baru(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Opening Balance');
        $sheet->setCellValue('A4', 'nama_klien (*)');
        $sheet->setCellValue('B4', 'kode_resto');
        $sheet->setCellValue('C4', 'nama_resto');
        $sheet->setCellValue('D4', 'tanggal (*)');
        $sheet->setCellValue('E4', 'saldo_awal (*)');
        $sheet->setCellValue('F4', 'keterangan');
        $sheet->setCellValue('G4', 'tipe_klien (*)');
        $sheet->setCellValue('H4', 'no_urut (*)');

        $sheet->setCellValue('A5', 'Klien Outlet');
        $sheet->setCellValue('B5', 'KD-001');
        $sheet->setCellValue('C5', 'Resto Contoh');
        $sheet->setCellValue('D5', '01-01-2023');
        $sheet->setCellValue('E5', '5000000');
        $sheet->setCellValue('F5', 'Keterangan baris');
        $sheet->setCellValue('G5', 'B2C');
        $sheet->setCellValue('H5', '1');

        $m = $this->ref->getMethod('parseObSheet');
        $m->setAccessible(true);
        $errors = [];
        $obRows = $m->invokeArgs($this->service, [$spreadsheet, &$errors]);

        $this->assertEmpty($errors);
        $this->assertCount(1, $obRows);
        $row = $obRows['1'];
        $this->assertSame('RESTO', $row['tipe_klien']);
        $this->assertSame('Klien Outlet', $row['nama_klien']);
        $this->assertSame('KD-001', $row['kode_resto']);
        $this->assertSame('Resto Contoh', $row['nama_resto']);
        $this->assertSame('2023-01-01', $row['tanggal']);
        $this->assertSame(5000000.0, $row['saldo_awal']);
        $this->assertSame('Keterangan baris', $row['keterangan']);
    }

    // ──────────────────────────────────────────────────────────────
    //  linkDetailsAndItems — logic bersama antara parseCsv() & parseXlsx()
    // ──────────────────────────────────────────────────────────────

    public function test_link_details_and_items_attaches_matching_items(): void
    {
        $obRows = ['1' => ['nama_klien' => 'Klien A']];
        $rawDetails = ['1' => [['source_row' => 2, 'no_invoice_asal' => 'INV-001', 'sisa_tagihan_asal' => 100.0]]];
        $rawItems = ['1|inv-001' => [['source_row' => 3, 'nama_barang' => 'Barang A']]];

        $result = $this->invoke('linkDetailsAndItems', $obRows, $rawDetails, $rawItems);

        $this->assertEmpty($result['errors']);
        $this->assertCount(1, $result['detailsByOb']['1'][0]['items']);
        $this->assertSame('Barang A', $result['detailsByOb']['1'][0]['items'][0]['nama_barang']);
    }

    public function test_link_details_and_items_detects_orphan_item(): void
    {
        $obRows = ['1' => ['nama_klien' => 'Klien A']];
        $rawDetails = ['1' => [['source_row' => 2, 'no_invoice_asal' => 'INV-001', 'sisa_tagihan_asal' => 100.0]]];
        $rawItems = ['1|inv-lain' => [['source_row' => 3, 'nama_barang' => 'Barang Yatim']]];

        $result = $this->invoke('linkDetailsAndItems', $obRows, $rawDetails, $rawItems);

        $this->assertEmpty($result['detailsByOb']['1'][0]['items']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame('Item Invoice Asal', $result['errors'][0]['sheet']);
    }

    public function test_link_details_and_items_detects_orphan_detail(): void
    {
        $obRows = []; // tidak ada OB dengan no_urut '1'
        $rawDetails = ['1' => [['source_row' => 2, 'no_invoice_asal' => 'INV-001', 'sisa_tagihan_asal' => 100.0]]];
        $rawItems = [];

        $result = $this->invoke('linkDetailsAndItems', $obRows, $rawDetails, $rawItems);

        $this->assertArrayNotHasKey('1', $result['detailsByOb'], 'Detail orphan harus dibuang dari hasil.');
        $this->assertCount(1, $result['errors']);
        $this->assertSame('Rincian Invoice Asal', $result['errors'][0]['sheet']);
    }
}
