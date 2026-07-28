<?php

namespace Tests\Feature;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceKoreksiService;
use App\Domain\Finance\EndingBalance\Services\EndingBalanceService;
use App\Domain\Finance\Invoice\Services\InvoiceGroupProcessor;
use App\Domain\Finance\Invoice\Services\InvoiceImportService;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Models\KlienAr;
use Tests\TestCase;

/**
 * Menguji inti aturan aman import invoice: klasifikasi grup terhadap invoice
 * existing. Semua method yang diuji di sini MURNI (tidak menyentuh DB), jadi bisa
 * dijalankan tanpa database — lihat catatan test DB di memory project.
 */
class InvoiceImportServiceTest extends TestCase
{
    private InvoiceImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new InvoiceImportService(
            $this->createMock(InvoiceGroupProcessor::class),
            $this->createMock(InvoiceService::class),
            $this->createMock(EndingBalanceService::class),
            $this->createMock(EndingBalanceKoreksiService::class),
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  Helper builder
    // ──────────────────────────────────────────────────────────────

    private function item(string $kode, float $qty, float $harga, array $extra = []): array
    {
        return array_merge([
            'kode_barang'      => $kode,
            'nama_barang'      => 'Barang ' . $kode,
            'qty'              => $qty,
            'satuan'           => 'pcs',
            'harga_satuan'     => $harga,
            'no_invoice_resto' => 'SI-001',
            'kode_resto'       => 'KD-001',
            'nama_resto'       => 'Resto A',
        ], $extra);
    }

    private function incoming(array $items, array $header = []): array
    {
        return [
            'items'  => $items,
            'header' => array_merge([
                'tanggal_jatuh_tempo' => '2026-06-30',
                'no_surat_jalan'      => 'SJ-001',
                'keterangan'          => null,
            ], $header),
        ];
    }

    private function existing(array $items, array $overrides = []): array
    {
        $subtotal = array_sum(array_map(fn($i) => $i['qty'] * $i['harga_satuan'], $items));

        return array_merge([
            'id'                 => 10,
            'no_invoice'         => 'INV-001',
            'status'             => 'TERKIRIM',
            'subtotal'           => $subtotal,
            'total_pembayaran'   => 0.0,
            'total_penyesuaian'  => 0.0,
            'has_pembayaran'     => false,
            'has_no_referensi'   => false,
            'bank_matched'       => false,
            'has_active_koreksi' => false,
            'items'              => $items,
            'header'             => [
                'tanggal_jatuh_tempo' => '2026-06-30',
                'no_surat_jalan'      => 'SJ-001',
                'keterangan'          => null,
            ],
        ], $overrides);
    }

    // ──────────────────────────────────────────────────────────────
    //  Invoice baru
    // ──────────────────────────────────────────────────────────────

    public function test_invoice_belum_ada_diklasifikasi_sebagai_new_invoice(): void
    {
        $result = $this->service->classifyGroup(
            $this->incoming([$this->item('BRG-1', 10, 5000)]),
            null,
            false,
        );

        $this->assertSame('NEW_INVOICE', $result['classification']);
        $this->assertSame(50000.0, $result['total_baru']);
        $this->assertSame([], $result['risk_flags']);
    }

    public function test_invoice_baru_di_periode_eb_terkunci_ditolak(): void
    {
        $result = $this->service->classifyGroup(
            $this->incoming([$this->item('BRG-1', 10, 5000)]),
            null,
            true,
        );

        $this->assertSame('REJECTED', $result['classification']);
        $this->assertContains('EB_LOCKED', $result['risk_flags']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Reupload data identik
    // ──────────────────────────────────────────────────────────────

    public function test_isi_sama_persis_diklasifikasi_unchanged(): void
    {
        $items = [$this->item('BRG-1', 10, 5000), $this->item('BRG-2', 2, 1000)];

        $result = $this->service->classifyGroup(
            $this->incoming($items),
            $this->existing($items),
            false,
        );

        $this->assertSame('UNCHANGED', $result['classification']);
        $this->assertSame(0.0, $result['selisih']);
    }

    public function test_urutan_item_berbeda_tetap_dianggap_unchanged(): void
    {
        $a = $this->item('BRG-1', 10, 5000);
        $b = $this->item('BRG-2', 2, 1000);

        $result = $this->service->classifyGroup(
            $this->incoming([$b, $a]),
            $this->existing([$a, $b]),
            false,
        );

        $this->assertSame('UNCHANGED', $result['classification']);
    }

    /** Invoice LUNAS pun tidak diapa-apakan kalau isinya memang tidak berubah. */
    public function test_invoice_lunas_tanpa_perubahan_tetap_unchanged(): void
    {
        $items = [$this->item('BRG-1', 10, 5000)];

        $result = $this->service->classifyGroup(
            $this->incoming($items),
            $this->existing($items, [
                'status'           => 'LUNAS',
                'total_pembayaran' => 50000.0,
                'has_pembayaran'   => true,
            ]),
            false,
        );

        $this->assertSame('UNCHANGED', $result['classification']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Update aman
    // ──────────────────────────────────────────────────────────────

    public function test_invoice_belum_dibayar_dengan_item_berubah_boleh_safe_update(): void
    {
        $result = $this->service->classifyGroup(
            $this->incoming([$this->item('BRG-1', 12, 5000)]),
            $this->existing([$this->item('BRG-1', 10, 5000)]),
            false,
        );

        $this->assertSame('SAFE_UPDATE', $result['classification']);
        $this->assertSame([], $result['risk_flags']);
        $this->assertNull($result['adjustment_type']);
    }

    public function test_invoice_belum_dibayar_dengan_header_berubah_boleh_safe_update(): void
    {
        $items = [$this->item('BRG-1', 10, 5000)];

        $result = $this->service->classifyGroup(
            $this->incoming($items, ['no_surat_jalan' => 'SJ-999']),
            $this->existing($items),
            false,
        );

        $this->assertSame('SAFE_UPDATE', $result['classification']);
        $this->assertArrayHasKey('no_surat_jalan', $result['header_diff']);
        $this->assertSame('SJ-001', $result['header_diff']['no_surat_jalan']['lama']);
        $this->assertSame('SJ-999', $result['header_diff']['no_surat_jalan']['baru']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Butuh review — invoice sudah tersentuh transaksi
    // ──────────────────────────────────────────────────────────────

    public function test_invoice_sebagian_dengan_no_referensi_masuk_review_bukan_overwrite(): void
    {
        $result = $this->service->classifyGroup(
            $this->incoming([$this->item('BRG-1', 8, 5000)]),
            $this->existing([$this->item('BRG-1', 10, 5000)], [
                'status'           => 'SEBAGIAN',
                'total_pembayaran' => 20000.0,
                'has_pembayaran'   => true,
                'has_no_referensi' => true,
            ]),
            false,
        );

        $this->assertSame('REVIEW_REQUIRED', $result['classification']);
        $this->assertContains('PEMBAYARAN', $result['risk_flags']);
        $this->assertContains('NO_REFERENSI', $result['risk_flags']);
        $this->assertContains('STATUS_SEBAGIAN', $result['risk_flags']);
    }

    public function test_invoice_cocok_rekening_koran_masuk_review(): void
    {
        $result = $this->service->classifyGroup(
            $this->incoming([$this->item('BRG-1', 11, 5000)]),
            $this->existing([$this->item('BRG-1', 10, 5000)], [
                'has_pembayaran' => true,
                'bank_matched'   => true,
            ]),
            false,
        );

        $this->assertSame('REVIEW_REQUIRED', $result['classification']);
        $this->assertContains('BANK_MATCHED', $result['risk_flags']);
    }

    public function test_invoice_lunas_yang_berubah_tidak_diupdate_langsung(): void
    {
        $result = $this->service->classifyGroup(
            $this->incoming([$this->item('BRG-1', 9, 5000)]),
            $this->existing([$this->item('BRG-1', 10, 5000)], [
                'status'           => 'LUNAS',
                'total_pembayaran' => 50000.0,
                'has_pembayaran'   => true,
            ]),
            false,
        );

        $this->assertSame('REVIEW_REQUIRED', $result['classification']);
        $this->assertContains('LUNAS', $result['risk_flags']);
        $this->assertSame('CREDIT_NOTE', $result['adjustment_type']);
    }

    public function test_invoice_dengan_penyesuaian_sebelumnya_masuk_review(): void
    {
        $result = $this->service->classifyGroup(
            $this->incoming([$this->item('BRG-1', 11, 5000)]),
            $this->existing([$this->item('BRG-1', 10, 5000)], ['total_penyesuaian' => -2500.0]),
            false,
        );

        $this->assertSame('REVIEW_REQUIRED', $result['classification']);
        $this->assertContains('PENYESUAIAN', $result['risk_flags']);
    }

    public function test_invoice_dengan_koreksi_pending_masuk_review(): void
    {
        $result = $this->service->classifyGroup(
            $this->incoming([$this->item('BRG-1', 11, 5000)]),
            $this->existing([$this->item('BRG-1', 10, 5000)], ['has_active_koreksi' => true]),
            false,
        );

        $this->assertSame('REVIEW_REQUIRED', $result['classification']);
        $this->assertContains('KOREKSI_PENDING', $result['risk_flags']);
    }

    /** EB terkunci tidak boleh di-update langsung, meski invoice-nya belum dibayar. */
    public function test_invoice_existing_di_periode_terkunci_masuk_review_bukan_safe_update(): void
    {
        $result = $this->service->classifyGroup(
            $this->incoming([$this->item('BRG-1', 11, 5000)]),
            $this->existing([$this->item('BRG-1', 10, 5000)]),
            true,
        );

        $this->assertSame('REVIEW_REQUIRED', $result['classification']);
        $this->assertContains('EB_LOCKED', $result['risk_flags']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Arah penyesuaian
    // ──────────────────────────────────────────────────────────────

    public function test_nominal_turun_menghasilkan_kandidat_credit_note(): void
    {
        $result = $this->service->classifyGroup(
            $this->incoming([$this->item('BRG-1', 6, 5000)]),   // 30.000
            $this->existing([$this->item('BRG-1', 10, 5000)], [ // 50.000
                'has_pembayaran'   => true,
                'total_pembayaran' => 10000.0,
            ]),
            false,
        );

        $this->assertSame('CREDIT_NOTE', $result['adjustment_type']);
        $this->assertSame(-20000.0, $result['selisih']);
        $this->assertSame(50000.0, $result['total_lama']);
        $this->assertSame(30000.0, $result['total_baru']);
    }

    public function test_nominal_naik_menghasilkan_kandidat_debit_note(): void
    {
        $result = $this->service->classifyGroup(
            $this->incoming([$this->item('BRG-1', 14, 5000)]),  // 70.000
            $this->existing([$this->item('BRG-1', 10, 5000)], [ // 50.000
                'has_pembayaran'   => true,
                'total_pembayaran' => 10000.0,
            ]),
            false,
        );

        $this->assertSame('DEBIT_NOTE', $result['adjustment_type']);
        $this->assertSame(20000.0, $result['selisih']);
    }

    public function test_nominal_sama_tapi_metadata_berubah_menjadi_review_metadata(): void
    {
        $result = $this->service->classifyGroup(
            $this->incoming([$this->item('BRG-1', 10, 5000, ['no_invoice_resto' => 'SI-BARU'])]),
            $this->existing([$this->item('BRG-1', 10, 5000)], ['has_pembayaran' => true]),
            false,
        );

        $this->assertSame('REVIEW_REQUIRED', $result['classification']);
        $this->assertSame('METADATA', $result['adjustment_type']);
        $this->assertSame(0.0, $result['selisih']);
    }

    /**
     * Kalau tagihan baru jadi lebih kecil dari pembayaran yang sudah masuk, import
     * TIDAK boleh auto-PDM — cukup ditandai supaya Finance yang memutuskan.
     */
    public function test_pembayaran_melebihi_tagihan_baru_hanya_ditandai(): void
    {
        $result = $this->service->classifyGroup(
            $this->incoming([$this->item('BRG-1', 2, 5000)]),   // 10.000
            $this->existing([$this->item('BRG-1', 10, 5000)], [ // 50.000
                'status'           => 'LUNAS',
                'has_pembayaran'   => true,
                'total_pembayaran' => 50000.0,
            ]),
            false,
        );

        $this->assertSame('REVIEW_REQUIRED', $result['classification']);
        $this->assertSame('CREDIT_NOTE', $result['adjustment_type']);
        $this->assertContains('PEMBAYARAN_MELEBIHI', $result['risk_flags']);
    }

    // ──────────────────────────────────────────────────────────────
    //  itemsSignature & diffHeader
    // ──────────────────────────────────────────────────────────────

    public function test_items_signature_tidak_sensitif_terhadap_urutan_dan_kapitalisasi(): void
    {
        $a = $this->service->itemsSignature([
            $this->item('brg-1', 10, 5000, ['nama_barang' => 'Produk A']),
            $this->item('BRG-2', 1, 100),
        ]);
        $b = $this->service->itemsSignature([
            $this->item('BRG-2', 1, 100),
            $this->item('BRG-1', 10, 5000, ['nama_barang' => 'produk a']),
        ]);

        $this->assertSame($a, $b);
    }

    public function test_items_signature_berubah_saat_harga_berbeda(): void
    {
        $a = $this->service->itemsSignature([$this->item('BRG-1', 10, 5000)]);
        $b = $this->service->itemsSignature([$this->item('BRG-1', 10, 5500)]);

        $this->assertNotSame($a, $b);
    }

    public function test_diff_header_menganggap_string_kosong_sama_dengan_null(): void
    {
        $diff = $this->service->diffHeader(
            ['no_surat_jalan' => '', 'keterangan' => 'x', 'tanggal_jatuh_tempo' => null],
            ['no_surat_jalan' => null, 'keterangan' => 'x', 'tanggal_jatuh_tempo' => null],
        );

        $this->assertSame([], $diff);
    }

    // ──────────────────────────────────────────────────────────────
    //  Validasi baris terhadap MASTER DATA
    //  (dipindahkan dari MasterImportServiceTest bersama logikanya)
    // ──────────────────────────────────────────────────────────────

    public function test_validasi_menolak_baris_tanpa_kode_resto(): void
    {
        $error = $this->service->validateRowAgainstMasterData('B2C', 'Investor A', null, []);

        $this->assertSame('kode_resto wajib diisi.', $error);
    }

    public function test_validasi_menolak_tipe_invoice_yang_tidak_cocok_dengan_segmen_outlet(): void
    {
        $map = ['KD-001' => ['tipe_klien' => 'RESTO', 'nama_klien' => 'Investor A', 'klien_id' => 3]];

        $error = $this->service->validateRowAgainstMasterData('B2B', 'Investor A', 'KD-001', $map);

        $this->assertStringContainsString("seharusnya 'B2C'", $error);
    }

    public function test_validasi_menolak_nama_klien_yang_tidak_sesuai_master_data(): void
    {
        $map = ['KD-001' => ['tipe_klien' => 'RESTO', 'nama_klien' => 'Investor A', 'klien_id' => 3]];

        $error = $this->service->validateRowAgainstMasterData('B2C', 'Investor Lain', 'KD-001', $map);

        $this->assertStringContainsString('tidak sesuai MASTER DATA', $error);
    }

    public function test_validasi_meloloskan_baris_yang_konsisten(): void
    {
        $map = ['KD-001' => ['tipe_klien' => 'RESTO', 'nama_klien' => 'Investor A', 'klien_id' => 3]];

        $this->assertNull($this->service->validateRowAgainstMasterData('B2C', 'investor a', 'kd-001', $map));
    }

    public function test_validasi_menolak_kode_resto_yang_tidak_ada_di_master_data(): void
    {
        $error = $this->service->validateRowAgainstMasterData('B2C', 'Investor X', 'KD-TIDAK-ADA', []);

        $this->assertStringContainsString('tidak ditemukan', $error);
    }

    /**
     * Regresi FB257/Veteran — kalau MASTER DATA sudah menyatakan outlet itu PT,
     * baris invoice B2C untuk kode_resto tsb wajib gagal, bukan diam-diam ter-resolve
     * ke Client AR RESTO lama (mis. "Ian Rizky Kurniawan").
     */
    public function test_validasi_fb257_terdaftar_pt_menolak_b2c(): void
    {
        $map = ['FB257' => ['tipe_klien' => 'PT', 'nama_klien' => 'PT. Arkhan Berkah Bersama', 'klien_id' => 5]];

        $error = $this->service->validateRowAgainstMasterData('B2C', 'Ian Rizky Kurniawan', 'FB257', $map);

        $this->assertNotNull($error);
        $this->assertStringContainsString('FB257', $error);
        $this->assertStringContainsString('PT', $error);
        $this->assertStringContainsString('B2B', $error);
    }

    // ──────────────────────────────────────────────────────────────
    //  resolveKlienForRow
    //  (dipindahkan dari MasterImportServiceTest bersama logikanya)
    // ──────────────────────────────────────────────────────────────

    private function makeKlien(int $id, string $namaKlien, string $tipeKlien): KlienAr
    {
        $klien = new KlienAr();
        $klien->forceFill([
            'id'         => $id,
            'nama_klien' => $namaKlien,
            'tipe_klien' => $tipeKlien,
        ]);

        return $klien;
    }

    /** resolveKlienForRow bersifat private — diakses via reflection agar visibility-nya tidak perlu dilonggarkan. */
    private function resolveKlien(mixed ...$args): array
    {
        $method = (new \ReflectionClass($this->service))->getMethod('resolveKlienForRow');
        $method->setAccessible(true);

        return $method->invoke($this->service, ...$args);
    }

    public function test_resolve_b2b_pakai_nama_map_dan_abaikan_kode_resto(): void
    {
        $klienPt = $this->makeKlien(1, 'PT Sejahtera', 'PT');

        // kode_resto sengaja diisi salah — B2B tetap resolve via nama (konsolidasi multi-outlet)
        [$klien, $error] = $this->resolveKlien('B2B', 'PT Sejahtera', 'KD-999', ['pt sejahtera' => $klienPt], [], [], []);

        $this->assertSame($klienPt, $klien);
        $this->assertNull($error);
    }

    public function test_resolve_b2b_tidak_ditemukan_mengembalikan_error(): void
    {
        [$klien, $error] = $this->resolveKlien('B2B', 'PT Tidak Ada', null, [], [], [], []);

        $this->assertNull($klien);
        $this->assertStringContainsString('tidak ditemukan', $error);
    }

    public function test_resolve_b2c_dengan_kode_resto_pakai_resto_map(): void
    {
        $outletA = $this->makeKlien(10, 'Investor X', 'RESTO');
        $outletB = $this->makeKlien(11, 'Investor X', 'RESTO');

        // lowercase, harus dinormalisasi ke upper
        [$klien, $error] = $this->resolveKlien('B2C', 'Investor X', 'kd-b', [], ['KD-A' => $outletA, 'KD-B' => $outletB], [], []);

        $this->assertSame($outletB, $klien);
        $this->assertNull($error);
    }

    public function test_resolve_b2c_kode_resto_salah_tidak_fallback_ke_nama(): void
    {
        $outletA = $this->makeKlien(10, 'Investor X', 'RESTO');

        // Meski nama investor cocok & tidak ambigu, kode_resto yang salah tidak boleh
        // fallback diam-diam ke pencocokan nama.
        [$klien, $error] = $this->resolveKlien(
            'B2C', 'Investor X', 'KD-SALAH',
            [], ['KD-A' => $outletA], ['investor x' => $outletA], ['investor x' => 1],
        );

        $this->assertNull($klien);
        $this->assertStringContainsString('KD-SALAH', $error);
        $this->assertStringContainsString('tidak ditemukan', $error);
    }

    public function test_resolve_b2c_kode_resto_kosong_outlet_tunggal_pakai_nama(): void
    {
        $outlet = $this->makeKlien(20, 'Investor Tunggal', 'RESTO');

        [$klien, $error] = $this->resolveKlien(
            'B2C', 'Investor Tunggal', null,
            [], [], ['investor tunggal' => $outlet], ['investor tunggal' => 1],
        );

        $this->assertSame($outlet, $klien);
        $this->assertNull($error);
    }

    public function test_resolve_b2c_kode_resto_kosong_multi_outlet_gagal(): void
    {
        $outletA = $this->makeKlien(30, 'Investor Banyak Outlet', 'RESTO');

        [$klien, $error] = $this->resolveKlien(
            'B2C', 'Investor Banyak Outlet', null,
            [], [], ['investor banyak outlet' => $outletA], ['investor banyak outlet' => 4],
        );

        $this->assertNull($klien);
        $this->assertStringContainsString('4 outlet', $error);
        $this->assertStringContainsString('kode_resto', $error);
    }

    public function test_resolve_b2c_kode_resto_kosong_klien_tidak_ada_mengembalikan_error(): void
    {
        [$klien, $error] = $this->resolveKlien('B2C', 'Investor Tidak Ada', null, [], [], [], []);

        $this->assertNull($klien);
        $this->assertStringContainsString('tidak ditemukan', $error);
    }
}
