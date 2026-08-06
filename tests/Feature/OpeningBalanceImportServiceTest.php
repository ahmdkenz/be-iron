<?php

namespace Tests\Feature;

use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\OpeningBalance\Services\OpeningBalanceImportService;
use App\Models\Barang;
use App\Models\KlienAr;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Unit test untuk logic parsing, agregasi & resolusi di OpeningBalanceImportService.
 * Menggunakan reflection agar private method bisa diakses tanpa mengubah visibility
 * (pola sama seperti MasterImportServiceTest — lihat memory project_test_db_migrate_fresh_broken:
 * migrate:fresh rusak di proyek ini, jadi test murni logic tanpa DB writes).
 *
 * Desain FLAT & AUTO-GROUP: `no_urut` adalah KUNCI GRUP (boleh berulang di banyak baris),
 * bukan ID unik per baris. Tes di sini fokus memverifikasi: (1) baris dengan no_urut sama
 * digabung jadi 1 grup alih-alih ditolak sebagai duplikat, (2) identitas klien hanya
 * diambil dari baris pertama tiap grup, (3) aturan agregasi buildDetailsForGroup() —
 * lump sum 1 baris vs rincian multi-baris, termasuk kegagalan parsial per baris.
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

    /** Untuk method dengan parameter by-reference (&$errors, &$rawItems) — invoke() dengan variadic spread tidak mempertahankan referensi. */
    private function invokeRef(string $method, array $args): mixed
    {
        $m = $this->ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($this->service, $args);
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

    private function groupRow(array $overrides = []): array
    {
        return array_merge([
            'sheet' => 'Data Opening Balance',
            'source_row' => 5,
            'no_urut' => '1',
            'tipe_klien' => 'PT',
            'nama_klien' => 'Klien A',
            'kode_resto' => null,
            'nama_resto' => null,
            'no_invoice_asal' => null,
            'tanggal_invoice_asal' => null,
            'sisa_tagihan_asal' => 1000000.0,
            'deskripsi' => '',
            'keterangan' => null,
        ], $overrides);
    }

    // ──────────────────────────────────────────────────────────────
    //  validateRowAgainstMasterData
    // ──────────────────────────────────────────────────────────────

    public function test_validasi_pt_meloloskan_kode_resto_kosong(): void
    {
        $this->assertNull($this->service->validateRowAgainstMasterData('PT', null, []));
    }

    /**
     * Sejak fix multi-resto (data nyata B2B: 100% baris punya kode_resto terisi, per
     * resto asal sebelum konsolidasi ke PT) — kode_resto untuk PT/B2B jadi opsional &
     * freeform, TIDAK divalidasi & TIDAK memengaruhi resolusi klien.
     */
    public function test_validasi_pt_meloloskan_kode_resto_terisi(): void
    {
        $this->assertNull($this->service->validateRowAgainstMasterData('PT', 'KD-001', []));
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

    public function test_import_date_menerima_nama_bulan_indonesia(): void
    {
        $this->assertSame('2026-05-28', $this->invoke('importDate', '28 Mei 2026'));
    }

    public function test_import_date_string_tidak_valid_mengembalikan_null(): void
    {
        $this->assertNull($this->invoke('importDate', 'tanggal ngasal'));
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

        $detected = $this->invoke('detectHeaderStart', $sheet, 'nama_klien', 10);

        $this->assertTrue($detected['found']);
        $this->assertSame(5, $detected['dataStart']);
    }

    public function test_detect_header_start_not_found_when_header_missing(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Bukan template sama sekali');

        $detected = $this->invoke('detectHeaderStart', $spreadsheet->getActiveSheet(), 'nama_klien', 10);

        $this->assertFalse($detected['found']);
    }

    // ──────────────────────────────────────────────────────────────
    //  buildDetailsForGroup — aturan agregasi 1 grup no_urut -> 1 Opening Balance
    // ──────────────────────────────────────────────────────────────

    public function test_build_details_for_group_1_baris_no_invoice_asal_kosong_jadi_lump_sum(): void
    {
        $rawItems = [];
        $errors = [];
        $result = $this->invokeRef('buildDetailsForGroup', [
            [$this->groupRow(['sisa_tagihan_asal' => 15000000.0, 'keterangan' => 'Saldo awal'])], &$rawItems, collect(), collect(), &$errors,
        ]);

        $this->assertNotNull($result);
        $this->assertEmpty($result['details']);
        $this->assertSame(15000000.0, $result['saldo_awal']);
        $this->assertSame('Saldo awal', $result['keterangan']);
        $this->assertEmpty($errors);
    }

    public function test_build_details_for_group_multi_baris_jadi_rincian_dan_saldo_sum(): void
    {
        $groupRows = [
            $this->groupRow(['source_row' => 5, 'no_invoice_asal' => 'INV-001', 'tanggal_invoice_asal' => '2023-01-15', 'sisa_tagihan_asal' => 2000000.0, 'deskripsi' => 'Invoice 1']),
            $this->groupRow(['source_row' => 6, 'tipe_klien' => null, 'nama_klien' => null, 'no_invoice_asal' => 'INV-002', 'tanggal_invoice_asal' => '2023-02-20', 'sisa_tagihan_asal' => 1500000.0, 'deskripsi' => 'Invoice 2']),
        ];
        $rawItems = [];
        $errors = [];
        $result = $this->invokeRef('buildDetailsForGroup', [$groupRows, &$rawItems, collect(), collect(), &$errors]);

        $this->assertNotNull($result);
        $this->assertCount(2, $result['details']);
        $this->assertSame(3500000.0, $result['saldo_awal']);
        $this->assertSame('INV-001', $result['details'][0]['no_invoice_asal']);
        $this->assertSame('INV-002', $result['details'][1]['no_invoice_asal']);
        $this->assertEmpty($errors);
    }

    public function test_build_details_for_group_1_baris_dengan_no_invoice_asal_tanpa_tanggal_gagal(): void
    {
        $rawItems = [];
        $errors = [];
        $result = $this->invokeRef('buildDetailsForGroup', [
            [$this->groupRow(['no_invoice_asal' => 'INV-001', 'tanggal_invoice_asal' => null])], &$rawItems, collect(), collect(), &$errors,
        ]);

        $this->assertNull($result);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('tanggal_invoice_asal', $errors[0]['message']);
    }

    public function test_build_details_for_group_baris_invalid_tidak_menggagalkan_baris_lain_dalam_grup(): void
    {
        $groupRows = [
            $this->groupRow(['source_row' => 5, 'no_invoice_asal' => 'INV-001', 'tanggal_invoice_asal' => '2023-01-15', 'sisa_tagihan_asal' => 1000000.0]),
            $this->groupRow(['source_row' => 6, 'tipe_klien' => null, 'nama_klien' => null, 'sisa_tagihan_asal' => 500000.0]), // no_invoice_asal & tanggal kosong
        ];
        $rawItems = [];
        $errors = [];
        $result = $this->invokeRef('buildDetailsForGroup', [$groupRows, &$rawItems, collect(), collect(), &$errors]);

        $this->assertNotNull($result, 'Grup masih valid karena masih ada 1 baris yang lolos.');
        $this->assertCount(1, $result['details']);
        $this->assertSame(1000000.0, $result['saldo_awal']);
        $this->assertCount(1, $errors);
        $this->assertSame(6, $errors[0]['row']);
    }

    public function test_build_details_for_group_semua_baris_invalid_mengembalikan_null(): void
    {
        $groupRows = [
            $this->groupRow(['source_row' => 5, 'no_invoice_asal' => 'INV-001', 'tanggal_invoice_asal' => null]),
            $this->groupRow(['source_row' => 6, 'tipe_klien' => null, 'nama_klien' => null]),
        ];
        $rawItems = [];
        $errors = [];
        $result = $this->invokeRef('buildDetailsForGroup', [$groupRows, &$rawItems, collect(), collect(), &$errors]);

        $this->assertNull($result);
        $this->assertCount(2, $errors);
    }

    public function test_build_details_for_group_melampirkan_item_dan_menghitung_jumlah_tagihan(): void
    {
        $groupRows = [$this->groupRow(['no_invoice_asal' => 'INV-001', 'tanggal_invoice_asal' => '2023-01-15', 'sisa_tagihan_asal' => 1000000.0])];
        $rawItems = [
            '1|inv-001' => [['source_row' => 10, 'kode_barang' => 'BRG-001', 'nama_barang' => 'Barang A', 'qty' => 2.0, 'satuan' => 'pcs', 'harga_satuan' => 300000.0, 'subtotal' => 0.0, 'keterangan' => null]],
            '2|inv-lain' => [['source_row' => 11, 'kode_barang' => null, 'nama_barang' => 'Barang Lain', 'qty' => 1.0, 'satuan' => 'pcs', 'harga_satuan' => 100000.0, 'subtotal' => 0.0, 'keterangan' => null]],
        ];
        $errors = [];
        $result = $this->invokeRef('buildDetailsForGroup', [$groupRows, &$rawItems, collect(), collect(), &$errors]);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['details'][0]['items']);
        $this->assertSame(600000.0, $result['details'][0]['jumlah_tagihan_asal']); // 2 x 300000
        $this->assertArrayNotHasKey('1|inv-001', $rawItems, 'Item yang cocok harus dikonsumsi (key dihapus).');
        $this->assertArrayHasKey('2|inv-lain', $rawItems, 'Item milik grup lain tidak boleh ikut terkonsumsi.');
    }

    /**
     * Regresi kunci fix multi-resto: $groupRows di sini merepresentasikan hasil GABUNGAN
     * dari 2 no_urut ASAL berbeda (mis. no_urut=3 & no_urut=4, dua-duanya resolve ke klien
     * PT yang sama — lihat process() pass 1 bucketing by klien_id). Tiap row tetap membawa
     * no_urut asalnya sendiri, dipakai untuk item-key — pastikan item tidak tertukar antar
     * baris meski sudah digabung ke 1 grup, dan kode_resto/nama_resto per baris terbawa ke
     * detail (info asal resto, sesuai permintaan user).
     */
    public function test_build_details_for_group_rows_gabungan_dari_no_urut_berbeda_link_item_dengan_benar(): void
    {
        $groupRows = [
            $this->groupRow(['source_row' => 5, 'no_urut' => '3', 'no_invoice_asal' => 'SI-A-001', 'tanggal_invoice_asal' => '2023-01-01', 'sisa_tagihan_asal' => 3000000.0, 'kode_resto' => 'KD-010', 'nama_resto' => 'Resto A']),
            $this->groupRow(['source_row' => 6, 'tipe_klien' => null, 'nama_klien' => null, 'no_urut' => '4', 'no_invoice_asal' => 'SI-B-001', 'tanggal_invoice_asal' => '2023-01-05', 'sisa_tagihan_asal' => 2500000.0, 'kode_resto' => 'KD-020', 'nama_resto' => 'Resto B']),
        ];
        $rawItems = [
            '3|si-a-001' => [['source_row' => 10, 'kode_barang' => 'BRG-A', 'nama_barang' => 'Barang A', 'qty' => 1.0, 'satuan' => 'pcs', 'harga_satuan' => 3000000.0, 'subtotal' => 0.0, 'keterangan' => null]],
            '4|si-b-001' => [['source_row' => 11, 'kode_barang' => 'BRG-B', 'nama_barang' => 'Barang B', 'qty' => 1.0, 'satuan' => 'pcs', 'harga_satuan' => 2500000.0, 'subtotal' => 0.0, 'keterangan' => null]],
        ];
        $errors = [];
        $result = $this->invokeRef('buildDetailsForGroup', [$groupRows, &$rawItems, collect(), collect(), &$errors]);

        $this->assertNotNull($result);
        $this->assertCount(2, $result['details']);
        $this->assertSame(5500000.0, $result['saldo_awal']); // 3.000.000 + 2.500.000, dari 2 no_urut asal berbeda
        $this->assertSame('KD-010', $result['details'][0]['kode_resto']);
        $this->assertSame('Resto A', $result['details'][0]['nama_resto']);
        $this->assertSame('KD-020', $result['details'][1]['kode_resto']);
        $this->assertSame('Resto B', $result['details'][1]['nama_resto']);
        $this->assertCount(1, $result['details'][0]['items'], 'Item milik no_urut=3 harus link ke rincian no_urut=3, bukan tertukar.');
        $this->assertSame('Barang A', $result['details'][0]['items'][0]['nama_barang']);
        $this->assertCount(1, $result['details'][1]['items']);
        $this->assertSame('Barang B', $result['details'][1]['items'][0]['nama_barang']);
        $this->assertEmpty($errors);
    }

    // ──────────────────────────────────────────────────────────────
    //  resolveKlienForOb — regresi multi-resto: no_urut per (PT+resto) tetap harus
    //  resolve ke KLIEN yang sama supaya process() bisa menggabungkannya jadi 1 OB.
    // ──────────────────────────────────────────────────────────────

    public function test_resolve_pt_kode_resto_berbeda_tetap_resolve_ke_klien_yang_sama(): void
    {
        $ptNamaGroups = collect([$this->makeKlien(7, 'PT Multi Resto', 'PT')])->groupBy(fn ($k) => strtolower(trim($k->nama_klien)));

        $result1 = $this->invoke('resolveKlienForOb', 'PT', 'PT Multi Resto', 'KD-010', $ptNamaGroups, collect());
        $result2 = $this->invoke('resolveKlienForOb', 'PT', 'PT Multi Resto', 'KD-020', $ptNamaGroups, collect());

        $this->assertSame(7, $result1['klien']->id);
        $this->assertSame(7, $result2['klien']->id, 'kode_resto berbeda TIDAK boleh memengaruhi resolusi klien PT — keduanya harus resolve ke klien yang sama supaya bisa digabung.');
    }

    // ──────────────────────────────────────────────────────────────
    //  parseObSheet (XLSX) — 1 baris = 1 invoice historis, no_urut = kunci grup
    // ──────────────────────────────────────────────────────────────

    private function fillObSheet(Spreadsheet $spreadsheet, array $rows): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Opening Balance');
        // tipe_klien & no_urut sengaja paling kanan (setelah keterangan) — lihat OB_HEADERS.
        $headers = ['nama_klien (*)', 'kode_resto', 'nama_resto', 'no_invoice_asal', 'tanggal_invoice_asal', 'sisa_tagihan_asal (*)', 'deskripsi', 'keterangan', 'tipe_klien (*)', 'no_urut (*)'];
        $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue("{$cols[$i]}4", $h);
        }
        foreach ($rows as $r => $vals) {
            foreach ($vals as $i => $v) {
                $sheet->setCellValue("{$cols[$i]}".(5 + $r), $v);
            }
        }
    }

    public function test_parse_ob_sheet_membaca_urutan_kolom_baru(): void
    {
        $spreadsheet = new Spreadsheet;
        $this->fillObSheet($spreadsheet, [
            ['Klien Outlet', 'KD-001', 'Resto Contoh', 'INV-001', '15-01-2023', '5000000', 'Deskripsi invoice', 'Keterangan baris', 'B2C', '1'],
        ]);

        $errors = [];
        $obRows = $this->invokeRef('parseObSheet', [$spreadsheet, &$errors]);

        $this->assertEmpty($errors);
        $this->assertCount(1, $obRows);
        $row = $obRows['1'][0];
        $this->assertSame('RESTO', $row['tipe_klien']);
        $this->assertSame('Klien Outlet', $row['nama_klien']);
        $this->assertSame('KD-001', $row['kode_resto']);
        $this->assertSame('Resto Contoh', $row['nama_resto']);
        $this->assertSame('INV-001', $row['no_invoice_asal']);
        $this->assertSame('2023-01-15', $row['tanggal_invoice_asal']);
        $this->assertSame(5000000.0, $row['sisa_tagihan_asal']);
        $this->assertSame('Deskripsi invoice', $row['deskripsi']);
        $this->assertSame('Keterangan baris', $row['keterangan']);
    }

    public function test_parse_ob_sheet_baris_dengan_no_urut_sama_digabung_jadi_1_grup(): void
    {
        $spreadsheet = new Spreadsheet;
        $this->fillObSheet($spreadsheet, [
            ['Klien Outlet', 'KD-001', 'Resto Contoh', 'INV-001', '15-01-2023', '2000000', '', '', 'B2C', '1'],
            ['', '', '', 'INV-002', '20-02-2023', '1500000', '', '', '', '1'],
        ]);

        $errors = [];
        $obRows = $this->invokeRef('parseObSheet', [$spreadsheet, &$errors]);

        $this->assertEmpty($errors, 'no_urut sama TIDAK boleh dianggap error duplikat lagi.');
        $this->assertCount(1, $obRows, 'no_urut sama harus jadi 1 grup.');
        $this->assertCount(2, $obRows['1']);
        $this->assertSame('Klien Outlet', $obRows['1'][0]['nama_klien']);
        $this->assertNull($obRows['1'][1]['nama_klien'], 'Baris kedua tidak wajib isi identitas — tidak diambil/divalidasi.');
        $this->assertSame('INV-001', $obRows['1'][0]['no_invoice_asal']);
        $this->assertSame('INV-002', $obRows['1'][1]['no_invoice_asal']);
        $this->assertSame('1', $obRows['1'][0]['no_urut']);
        $this->assertSame('1', $obRows['1'][1]['no_urut']);
    }

    /**
     * Regresi fix multi-resto: kode_resto/nama_resto TIDAK lagi dibatasi ke baris pertama
     * saja — setiap baris B2B boleh punya kode_resto sendiri (info asal resto per invoice),
     * tidak dibuang seperti nama_klien/tipe_klien pada baris kedua dst.
     */
    public function test_parse_ob_sheet_kode_resto_direkam_di_setiap_baris_bukan_cuma_pertama(): void
    {
        $spreadsheet = new Spreadsheet;
        $this->fillObSheet($spreadsheet, [
            ['PT Multi Resto', 'KD-010', 'Resto A', 'SI-A-001', '01-01-2023', '3000000', '', '', 'B2B', '3'],
            ['', 'KD-020', 'Resto B', 'SI-B-001', '05-01-2023', '2500000', '', '', '', '3'],
        ]);

        $errors = [];
        $obRows = $this->invokeRef('parseObSheet', [$spreadsheet, &$errors]);

        $this->assertEmpty($errors);
        $this->assertSame('KD-010', $obRows['3'][0]['kode_resto']);
        $this->assertSame('KD-020', $obRows['3'][1]['kode_resto'], 'kode_resto baris kedua harus tetap terekam, bukan null.');
        $this->assertSame('Resto B', $obRows['3'][1]['nama_resto']);
    }

    /**
     * Regresi: baris kelanjutan contoh (baris ke-2 dst dari 1 grup no_urut contoh) sengaja
     * mengosongkan nama_klien — penanda "[CONTOH]" cuma ada di kolom deskripsi. Baris ini
     * HARUS tetap diabaikan (bukan diproses sebagai data sungguhan dengan tipe_klien kosong,
     * yang akan memicu error palsu kalau user membiarkan baris contoh di file).
     */
    public function test_parse_ob_sheet_baris_kelanjutan_contoh_diabaikan_via_deskripsi(): void
    {
        $spreadsheet = new Spreadsheet;
        $this->fillObSheet($spreadsheet, [
            ['[CONTOH] Nama Klien Outlet', 'KD-001', 'Nama Resto Contoh', 'INV-LAMA-001', '15-01-2022', '9000000', '[CONTOH] Tagihan pengiriman Januari 2022', '', 'B2C', '2'],
            ['', '', '', 'INV-LAMA-002', '20-02-2022', '6000000', '[CONTOH] Tagihan pengiriman Februari 2022', '', '', '2'],
        ]);

        $errors = [];
        $obRows = $this->invokeRef('parseObSheet', [$spreadsheet, &$errors]);

        $this->assertEmpty($errors, 'Baris kelanjutan contoh tidak boleh menghasilkan error palsu.');
        $this->assertEmpty($obRows, 'Kedua baris contoh (baris pertama & kelanjutannya) harus diabaikan.');
    }

    public function test_parse_ob_sheet_sisa_tagihan_asal_wajib_lebih_dari_nol(): void
    {
        $spreadsheet = new Spreadsheet;
        $this->fillObSheet($spreadsheet, [
            ['Klien A', '', '', '', '', '0', '', '', 'B2B', '1'],
        ]);

        $errors = [];
        $obRows = $this->invokeRef('parseObSheet', [$spreadsheet, &$errors]);

        $this->assertEmpty($obRows);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('sisa_tagihan_asal', $errors[0]['message']);
    }

    public function test_parse_ob_sheet_header_format_lama_tipe_klien_di_kolom_d_ditolak_jelas(): void
    {
        // Format sebelum tipe_klien/no_urut dipindah ke paling kanan: tipe_klien masih di
        // kolom D (bukan I). Kolom A tetap "nama_klien" di semua versi, jadi ini menguji
        // guard tambahan di parseObSheet() yang secara eksplisit memverifikasi kolom I —
        // bukan cuma cek header nama_klien.
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Opening Balance');
        $oldHeaders = ['nama_klien (*)', 'kode_resto', 'nama_resto', 'tipe_klien (*)', 'no_urut (*)', 'no_invoice_asal', 'tanggal_invoice_asal', 'sisa_tagihan_asal (*)', 'deskripsi', 'keterangan'];
        $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
        foreach ($oldHeaders as $i => $h) {
            $sheet->setCellValue("{$cols[$i]}4", $h);
        }
        $sheet->setCellValue('A5', 'Klien Lama');
        $sheet->setCellValue('D5', 'PT');
        $sheet->setCellValue('E5', '1');
        $sheet->setCellValue('H5', '1000000');

        $errors = [];
        $obRows = $this->invokeRef('parseObSheet', [$spreadsheet, &$errors]);

        $this->assertEmpty($obRows, 'Tidak boleh mencoba parse baris data dari file format lama.');
        $this->assertCount(1, $errors, 'Harus 1 error jelas, bukan error per-baris yang membingungkan.');
        $this->assertStringContainsString('Download ulang Template XLSX', $errors[0]['message']);
    }

    public function test_parse_ob_sheet_baris_pertama_grup_wajib_tipe_klien_valid(): void
    {
        $spreadsheet = new Spreadsheet;
        $this->fillObSheet($spreadsheet, [
            ['Klien A', '', '', '', '', '1000000', '', '', 'BUKAN_VALID', '1'],
        ]);

        $errors = [];
        $obRows = $this->invokeRef('parseObSheet', [$spreadsheet, &$errors]);

        $this->assertEmpty($obRows);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('tipe_klien', $errors[0]['message']);
    }

    // ──────────────────────────────────────────────────────────────
    //  parseCsv — 1 tabel flat dibedakan kolom tipe_baris (OB/ITEM)
    // ──────────────────────────────────────────────────────────────

    private const CSV_HEADER = 'tipe_baris;no_urut;nama_klien;kode_resto;nama_resto;tipe_klien;no_invoice_asal;tanggal_invoice_asal;sisa_tagihan_asal (*);deskripsi;keterangan;kode_barang;nama_barang;qty;satuan;harga_satuan;subtotal';

    private function writeTempCsv(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ob_import_test_').'.csv';
        file_put_contents($path, implode("\n", $lines));

        return $path;
    }

    /** Susun 1 baris CSV dari kolom bernama (indeks sesuai CSV_COL_* di service) — kolom yang tidak diisi otomatis kosong. */
    private function csvRow(array $cols): string
    {
        $order = ['tipe_baris', 'no_urut', 'nama_klien', 'kode_resto', 'nama_resto', 'tipe_klien', 'no_invoice_asal', 'tanggal_invoice_asal', 'sisa_tagihan_asal', 'deskripsi', 'keterangan', 'kode_barang', 'nama_barang', 'qty', 'satuan', 'harga_satuan', 'subtotal'];

        return implode(';', array_map(fn ($key) => $cols[$key] ?? '', $order));
    }

    public function test_parse_csv_skips_comments_examples_and_blank_rows(): void
    {
        $path = $this->writeTempCsv([
            '# TEMPLATE IMPORT MASTER OPENING BALANCE (CSV)',
            '# beberapa baris instruksi lain',
            self::CSV_HEADER,
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '1', 'tipe_klien' => 'PT', 'nama_klien' => '[CONTOH] Nama Klien Contoh', 'sisa_tagihan_asal' => '15000000']),
            str_repeat(';', 16),
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '2', 'tipe_klien' => 'PT', 'nama_klien' => 'Klien Nyata', 'sisa_tagihan_asal' => '5000000', 'keterangan' => 'Keterangan asli']),
        ]);

        try {
            [$obRows, $rawItems, $errors] = $this->invoke('parseCsv', $path);

            $this->assertEmpty($errors);
            $this->assertEmpty($rawItems);
            $this->assertCount(1, $obRows, 'Baris [CONTOH] dan baris kosong harus dilewati.');

            $group = array_values($obRows)[0];
            $this->assertCount(1, $group);
            $row = $group[0];
            $this->assertSame('PT', $row['tipe_klien']);
            $this->assertSame('Klien Nyata', $row['nama_klien']);
            $this->assertSame(5000000.0, $row['sisa_tagihan_asal']);
            $this->assertSame('Keterangan asli', $row['keterangan']);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_csv_captures_tipe_klien_dan_kode_resto(): void
    {
        $path = $this->writeTempCsv([
            self::CSV_HEADER,
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '1', 'tipe_klien' => 'RESTO', 'nama_klien' => 'Klien Outlet', 'kode_resto' => 'kd-001', 'nama_resto' => 'Resto Contoh', 'sisa_tagihan_asal' => '1000000']),
        ]);

        try {
            [$obRows, , $errors] = $this->invoke('parseCsv', $path);

            $this->assertEmpty($errors);
            $this->assertCount(1, $obRows);
            $row = array_values($obRows)[0][0];
            $this->assertSame('RESTO', $row['tipe_klien']);
            $this->assertSame('kd-001', $row['kode_resto']);
            $this->assertSame('Resto Contoh', $row['nama_resto']);
            $this->assertSame('Klien Outlet', $row['nama_klien']);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_csv_baris_kelanjutan_contoh_diabaikan_via_deskripsi(): void
    {
        $path = $this->writeTempCsv([
            self::CSV_HEADER,
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '2', 'tipe_klien' => 'B2C', 'nama_klien' => '[CONTOH] Nama Klien Outlet', 'kode_resto' => 'KD-001', 'no_invoice_asal' => 'INV-LAMA-001', 'tanggal_invoice_asal' => '15-01-2022', 'sisa_tagihan_asal' => '9000000', 'deskripsi' => '[CONTOH] Tagihan pengiriman Januari 2022']),
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '2', 'no_invoice_asal' => 'INV-LAMA-002', 'tanggal_invoice_asal' => '20-02-2022', 'sisa_tagihan_asal' => '6000000', 'deskripsi' => '[CONTOH] Tagihan pengiriman Februari 2022']),
        ]);

        try {
            [$obRows, , $errors] = $this->invoke('parseCsv', $path);

            $this->assertEmpty($errors, 'Baris kelanjutan contoh tidak boleh menghasilkan error palsu.');
            $this->assertEmpty($obRows, 'Kedua baris contoh (baris pertama & kelanjutannya) harus diabaikan.');
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_csv_tipe_klien_invalid_reports_error(): void
    {
        $path = $this->writeTempCsv([
            self::CSV_HEADER,
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '1', 'tipe_klien' => 'BUKAN_VALID', 'nama_klien' => 'Klien X', 'sisa_tagihan_asal' => '1000000']),
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
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '1', 'tipe_klien' => 'b2b', 'nama_klien' => 'Klien Konsolidasi', 'sisa_tagihan_asal' => '1000000']),
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '2', 'tipe_klien' => 'B2C', 'nama_klien' => 'Klien Outlet', 'kode_resto' => 'KD-002', 'sisa_tagihan_asal' => '2000000']),
        ]);

        try {
            [$obRows, , $errors] = $this->invoke('parseCsv', $path);

            $this->assertEmpty($errors);
            $this->assertCount(2, $obRows);
            $this->assertSame('PT', $obRows['1'][0]['tipe_klien']);
            $this->assertSame('RESTO', $obRows['2'][0]['tipe_klien']);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_csv_missing_nama_klien_reports_error(): void
    {
        $path = $this->writeTempCsv([
            self::CSV_HEADER,
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '1', 'tipe_klien' => 'PT', 'sisa_tagihan_asal' => '1000000']),
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

    public function test_parse_csv_sisa_tagihan_asal_wajib_lebih_dari_nol(): void
    {
        $path = $this->writeTempCsv([
            self::CSV_HEADER,
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '1', 'tipe_klien' => 'PT', 'nama_klien' => 'Klien A', 'sisa_tagihan_asal' => '0']),
        ]);

        try {
            [$obRows, , $errors] = $this->invoke('parseCsv', $path);

            $this->assertEmpty($obRows);
            $this->assertCount(1, $errors);
            $this->assertStringContainsString('sisa_tagihan_asal', $errors[0]['message']);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_csv_baris_ob_dengan_no_urut_sama_digabung_jadi_1_grup(): void
    {
        $path = $this->writeTempCsv([
            self::CSV_HEADER,
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '1', 'tipe_klien' => 'RESTO', 'nama_klien' => 'Klien Outlet', 'kode_resto' => 'KD-001', 'no_invoice_asal' => 'INV-001', 'tanggal_invoice_asal' => '15-01-2023', 'sisa_tagihan_asal' => '2000000']),
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '1', 'no_invoice_asal' => 'INV-002', 'tanggal_invoice_asal' => '20-02-2023', 'sisa_tagihan_asal' => '1500000']),
        ]);

        try {
            [$obRows, , $errors] = $this->invoke('parseCsv', $path);

            $this->assertEmpty($errors, 'no_urut sama TIDAK boleh dianggap error duplikat lagi.');
            $this->assertCount(1, $obRows);
            $this->assertCount(2, $obRows['1']);
            $this->assertSame('Klien Outlet', $obRows['1'][0]['nama_klien']);
            $this->assertNull($obRows['1'][1]['nama_klien'], 'Baris kedua tidak wajib isi identitas.');
            $this->assertSame('1', $obRows['1'][0]['no_urut']);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_csv_kode_resto_direkam_di_setiap_baris_bukan_cuma_pertama(): void
    {
        $path = $this->writeTempCsv([
            self::CSV_HEADER,
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '3', 'tipe_klien' => 'B2B', 'nama_klien' => 'PT Multi Resto', 'kode_resto' => 'KD-010', 'nama_resto' => 'Resto A', 'no_invoice_asal' => 'SI-A-001', 'tanggal_invoice_asal' => '01-01-2023', 'sisa_tagihan_asal' => '3000000']),
            $this->csvRow(['tipe_baris' => 'OB', 'no_urut' => '3', 'kode_resto' => 'KD-020', 'nama_resto' => 'Resto B', 'no_invoice_asal' => 'SI-B-001', 'tanggal_invoice_asal' => '05-01-2023', 'sisa_tagihan_asal' => '2500000']),
        ]);

        try {
            [$obRows, , $errors] = $this->invoke('parseCsv', $path);

            $this->assertEmpty($errors);
            $this->assertSame('KD-010', $obRows['3'][0]['kode_resto']);
            $this->assertSame('KD-020', $obRows['3'][1]['kode_resto'], 'kode_resto baris kedua harus tetap terekam, bukan null.');
            $this->assertSame('Resto B', $obRows['3'][1]['nama_resto']);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_csv_header_format_lama_ditolak_jelas(): void
    {
        // Format sesi sebelumnya: tipe_baris, no_urut, nama_klien, kode_resto, nama_resto,
        // tanggal, saldo_awal, tipe_klien (indeks 7) — tipe_klien TIDAK lagi di indeks 5.
        $oldHeader = 'tipe_baris;no_urut;nama_klien (*);kode_resto;nama_resto;tanggal (*);saldo_awal (*);tipe_klien;no_invoice_asal (*);tanggal_invoice_asal (*);deskripsi;jumlah_tagihan_asal;sisa_tagihan_asal (*);kode_barang;nama_barang;qty;satuan;harga_satuan;subtotal;keterangan';
        $path = $this->writeTempCsv([
            $oldHeader,
            'OB;1;Nama Klien Contoh;;;01-01-2023;15000000;PT;;;;;;;;;;;;',
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

    public function test_parse_csv_item_row_masuk_ke_raw_items_keyed_by_no_urut_dan_no_invoice_asal(): void
    {
        $path = $this->writeTempCsv([
            self::CSV_HEADER,
            $this->csvRow(['tipe_baris' => 'ITEM', 'no_urut' => '1', 'no_invoice_asal' => 'INV-ASAL-001', 'kode_barang' => 'BRG-001', 'nama_barang' => 'Barang Contoh', 'qty' => '4', 'satuan' => 'pcs', 'harga_satuan' => '500000']),
        ]);

        try {
            [$obRows, $rawItems, $errors] = $this->invoke('parseCsv', $path);

            $this->assertEmpty($errors);
            $this->assertEmpty($obRows);
            $this->assertArrayHasKey('1|inv-asal-001', $rawItems);
            $item = $rawItems['1|inv-asal-001'][0];
            $this->assertSame('BRG-001', $item['kode_barang']);
            $this->assertSame(4.0, $item['qty']);
            $this->assertSame(500000.0, $item['harga_satuan']);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_csv_item_missing_no_urut_or_no_invoice_asal_reports_error(): void
    {
        $path = $this->writeTempCsv([
            self::CSV_HEADER,
            $this->csvRow(['tipe_baris' => 'ITEM', 'no_urut' => '', 'no_invoice_asal' => '', 'nama_barang' => 'Barang X']),
        ]);

        try {
            [, , $errors] = $this->invoke('parseCsv', $path);

            $this->assertCount(1, $errors);
            $this->assertStringContainsString('no_urut dan no_invoice_asal wajib diisi', $errors[0]['message']);
        } finally {
            @unlink($path);
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  detectFormulaError() — rumus Excel yang gagal dievaluasi (mis. =A1/0) dikembalikan
    //  PhpSpreadsheet sebagai string kode error, bukan exception, sehingga tanpa deteksi
    //  khusus akan lolos ke importNum()/importDate() dan diam-diam jadi 0/null.
    // ──────────────────────────────────────────────────────────────

    public function test_detect_formula_error_mendeteksi_semua_kode_error_excel(): void
    {
        $codes = ['#DIV/0!', '#REF!', '#VALUE!', '#NAME?', '#NULL!', '#NUM!', '#N/A', '#GETTING_DATA', '#SPILL!', '#CALC!'];

        foreach ($codes as $code) {
            $row = ['Klien A', '', $code, '', '', '', '', ''];

            $message = $this->invoke('detectFormulaError', $row, 'Data Opening Balance', 12);

            $this->assertNotNull($message, "Kode error {$code} harus terdeteksi.");
            $this->assertStringContainsString('Baris 12', $message);
            $this->assertStringContainsString($code, $message);
        }
    }

    public function test_detect_formula_error_null_untuk_baris_normal(): void
    {
        $row = ['Klien A', 'RESTO01', 'RESTO01', 'B2C', '1', 'INV-001', '01-06-2026', '100000', 'Deskripsi', 'Keterangan'];

        $this->assertNull($this->invoke('detectFormulaError', $row, 'Data Opening Balance', 12));
    }

    public function test_detect_formula_error_menyertakan_nama_sheet_yang_benar_untuk_multi_sheet(): void
    {
        $row = ['1', 'INV-001', '#REF!', 'Deskripsi', '', '', ''];

        $message = $this->invoke('detectFormulaError', $row, 'Item Invoice Asal', 8);

        $this->assertStringContainsString("sheet 'Item Invoice Asal'", $message, 'Pesan harus menyebut nama sheet yang benar supaya baris 8 tidak ambigu antar sheet.');
    }

    public function test_detect_formula_error_melaporkan_huruf_kolom_yang_akurat(): void
    {
        // Index 4 (0-based) = kolom E di file fisik (A=0, B=1, C=2, D=3, E=4).
        $row = ['', '', '', '', '#DIV/0!'];

        $message = $this->invoke('detectFormulaError', $row, 'Data Opening Balance', 20);

        $this->assertStringContainsString('kolom E', $message, 'Huruf kolom harus cocok dengan posisi fisik di file Excel (index 4 = kolom E).');
    }
}
