<?php

namespace Tests\Feature;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceKoreksiService;
use App\Domain\Finance\EndingBalance\Services\EndingBalanceService;
use App\Domain\Finance\Invoice\Services\InvoiceGroupProcessor;
use App\Domain\Finance\Invoice\Services\InvoiceImportService;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Models\KlienAr;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as PhpSpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * Menguji inti aturan aman import invoice: klasifikasi grup terhadap invoice
 * existing. Semua method yang diuji di sini MURNI (tidak menyentuh DB), jadi bisa
 * dijalankan tanpa database — lihat catatan test DB di memory project.
 */
class InvoiceImportServiceTest extends TestCase
{
    private InvoiceImportService $service;

    /** @var string[] */
    private array $tmpFiles = [];

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

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    /** Reflection generik untuk memanggil private method — dipakai test CSV baru di bawah. */
    private function invoke(string $method, mixed ...$args): mixed
    {
        $m = (new \ReflectionClass($this->service))->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($this->service, ...$args);
    }

    /** Tulis $rows ke file CSV sementara (dibersihkan di tearDown), kembalikan path-nya. */
    private function buildCsv(array $rows, string $delimiter = ';'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'invoice_csv_test_') . '.csv';
        $this->tmpFiles[] = $path;

        $handle = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row, $delimiter);
        }
        fclose($handle);

        return $path;
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

    // ──────────────────────────────────────────────────────────────
    //  Parsing CSV native (scanCsvHeaderAndCount / chunkCsvRows)
    // ──────────────────────────────────────────────────────────────

    private function sampleCsvRows(): array
    {
        return [
            ['# TEMPLATE IMPORT MASTER INVOICE (B2B & B2C)'],
            ['# Baris instruksi kedua, isinya bebas.'],
            ['nama_klien (*)', 'tanggal_invoice (*)', 'tanggal_jatuh_tempo', 'no_surat_jalan', 'keterangan_invoice',
                'no_invoice_resto', 'kode_resto (*)', 'nama_resto', 'kode_barang', 'nama_barang (*)',
                'qty (*)', 'satuan', 'harga_satuan (*)', 'tipe_invoice (*)'],
            ['Klien Satu', '01-06-2026', '30-06-2026', 'SJ-001', '', '', 'KD-001', 'Resto Satu', 'BRG-001', 'Barang A', '10', 'pcs', '1000', 'B2C'],
            ['', '', '', '', '', '', '', '', '', '', '', '', '', ''], // baris kosong, harus dilewati
            ['Klien Satu', '01-06-2026', '30-06-2026', 'SJ-001', '', '', 'KD-001', 'Resto Satu', 'BRG-002', 'Barang B', '5', 'kg', '2000', 'B2C'],
            ['Klien Dua', '02-06-2026', '30-06-2026', '', '', 'SI-001', 'KD-002', 'Resto Dua', 'BRG-003', 'Barang C', '3', 'pcs', '3000', 'B2B'],
        ];
    }

    public function test_scan_csv_header_and_count_menemukan_header_dan_total_baris_benar(): void
    {
        $file = $this->buildCsv($this->sampleCsvRows());

        $scan = $this->invoke('scanCsvHeaderAndCount', $file);

        $this->assertSame(2, $scan['headerLineIdx']); // 0-based: baris ke-3 (setelah 2 baris instruksi)
        $this->assertSame(4, $scan['totalRows']);      // 4 baris setelah header (termasuk 1 baris kosong)
        $this->assertSame(';', $scan['delimiter']);
    }

    public function test_chunk_csv_rows_tidak_kehilangan_atau_menduplikasi_baris(): void
    {
        $file = $this->buildCsv($this->sampleCsvRows());
        $scan = $this->invoke('scanCsvHeaderAndCount', $file);

        $collected = [];
        $this->invoke('chunkCsvRows', $file, $scan['headerLineIdx'] + 1, $scan['delimiter'],
            function (array $row) use (&$collected) { $collected[] = $row[0] ?? ''; });

        $this->assertCount(4, $collected, 'Tidak boleh ada baris hilang/dobel (termasuk baris kosong).');
        $this->assertSame(['Klien Satu', '', 'Klien Satu', 'Klien Dua'], $collected);
    }

    public function test_csv_delimiter_koma_dan_titik_koma_menghasilkan_hasil_identik(): void
    {
        $rows = $this->sampleCsvRows();

        $fileSemicolon = $this->buildCsv($rows, ';');
        $fileComma     = $this->buildCsv($rows, ',');

        $scanSemicolon = $this->invoke('scanCsvHeaderAndCount', $fileSemicolon);
        $scanComma     = $this->invoke('scanCsvHeaderAndCount', $fileComma);

        $this->assertSame(';', $scanSemicolon['delimiter']);
        $this->assertSame(',', $scanComma['delimiter']);
        $this->assertSame($scanSemicolon['headerLineIdx'], $scanComma['headerLineIdx']);
        $this->assertSame($scanSemicolon['totalRows'], $scanComma['totalRows']);

        $collect = function (string $file, array $scan): array {
            $rows = [];
            $this->invoke('chunkCsvRows', $file, $scan['headerLineIdx'] + 1, $scan['delimiter'],
                function (array $row) use (&$rows) { $rows[] = $row; });

            return $rows;
        };

        $this->assertSame($collect($fileSemicolon, $scanSemicolon), $collect($fileComma, $scanComma));
    }

    // ──────────────────────────────────────────────────────────────
    //  Parsing XLSX (findSheetIndex / detectInvoiceHeaderStart / chunkXlsxRows)
    // ──────────────────────────────────────────────────────────────

    /** Tulis $rows ke file xlsx sementara (dibersihkan di tearDown), kembalikan path-nya. */
    private function buildXlsx(array $rows, string $sheetTitle = 'MASTER INVOICE'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetTitle);

        foreach ($rows as $rowIdx => $row) {
            foreach ($row as $colIdx => $value) {
                $col = Coordinate::stringFromColumnIndex($colIdx + 1);
                $sheet->setCellValueExplicit($col . ($rowIdx + 1), $value, DataType::TYPE_STRING);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'invoice_xlsx_test_') . '.xlsx';
        $this->tmpFiles[] = $path;
        (new XlsxWriter($spreadsheet))->save($path);

        return $path;
    }

    private function loadXlsxSheet(string $path, string $sheetName = 'MASTER INVOICE'): Worksheet
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $idx = $this->invoke('findSheetIndex', $spreadsheet, $sheetName);

        return $spreadsheet->getSheet($idx);
    }

    /** Baris 1-2 judul/subjudul, baris 3 kosong, baris 4 header, baris 5-8 data (1 baris kosong disisipkan). */
    private function sampleXlsxRows(): array
    {
        return [
            ['TEMPLATE IMPORT MASTER INVOICE (B2B & B2C)'],
            ['Baris subtitle instruksi, isinya bebas.'],
            [],
            ['nama_klien (*)', 'tanggal_invoice (*)', 'tanggal_jatuh_tempo', 'no_surat_jalan', 'keterangan_invoice',
                'no_invoice_resto', 'kode_resto (*)', 'nama_resto', 'kode_barang', 'nama_barang (*)',
                'qty (*)', 'satuan', 'harga_satuan (*)', 'tipe_invoice (*)'],
            ['Klien Satu', '01-06-2026', '30-06-2026', 'SJ-001', '', '', 'KD-001', 'Resto Satu', 'BRG-001', 'Barang A', '10', 'pcs', '1000', 'B2C'],
            [], // baris kosong, harus dilewati
            ['Klien Satu', '01-06-2026', '30-06-2026', 'SJ-001', '', '', 'KD-001', 'Resto Satu', 'BRG-002', 'Barang B', '5', 'kg', '2000', 'B2C'],
            ['Klien Dua', '02-06-2026', '30-06-2026', '', '', 'SI-001', 'KD-002', 'Resto Dua', 'BRG-003', 'Barang C', '3', 'pcs', '3000', 'B2B'],
        ];
    }

    public function test_find_sheet_index_case_insensitive(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setTitle('master invoice');
        $spreadsheet->createSheet()->setTitle('Petunjuk Pengisian');

        $this->assertSame(0, $this->invoke('findSheetIndex', $spreadsheet, 'MASTER INVOICE'));
    }

    public function test_find_sheet_index_tidak_ditemukan_mengembalikan_null(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setTitle('Sheet Lain');

        $this->assertNull($this->invoke('findSheetIndex', $spreadsheet, 'MASTER INVOICE'));
    }

    public function test_detect_invoice_header_start_menemukan_baris_header_dan_data(): void
    {
        $sheet = $this->loadXlsxSheet($this->buildXlsx($this->sampleXlsxRows()));

        $header = $this->invoke('detectInvoiceHeaderStart', $sheet);

        $this->assertTrue($header['found']);
        $this->assertSame(5, $header['dataStart']);
        $this->assertSame('N', $header['highestColumn']);
        $this->assertSame(8, $header['highestRow']);
    }

    public function test_chunk_xlsx_rows_tidak_kehilangan_atau_menduplikasi_baris(): void
    {
        $sheet  = $this->loadXlsxSheet($this->buildXlsx($this->sampleXlsxRows()));
        $header = $this->invoke('detectInvoiceHeaderStart', $sheet);

        $collected   = [];
        $rowNumbers  = [];
        // chunkSize=2 dengan 4 baris data memaksa 2 kali putaran chunk+removeRow().
        $this->invoke('chunkXlsxRows', $sheet, $header['dataStart'], $header['highestColumn'], 2,
            function (array $row, int $rowNumber) use (&$collected, &$rowNumbers) {
                $collected[]  = $row[0] ?? '';
                $rowNumbers[] = $rowNumber;
            });

        $this->assertCount(4, $collected, 'Tidak boleh ada baris hilang/dobel (termasuk baris kosong), walau dibaca per-chunk.');
        $this->assertSame(['Klien Satu', '', 'Klien Satu', 'Klien Dua'], $collected);
        $this->assertSame([5, 6, 7, 8], $rowNumbers, 'Nomor baris harus tetap merujuk ke baris asli Excel walau baris internal sudah di-removeRow().');
    }

    // ──────────────────────────────────────────────────────────────
    //  importDate() — fallback serial number Excel (bug ditemukan & diperbaiki
    //  saat menambahkan jalur upload xlsx: cell bertipe Date asli terbaca sebagai
    //  angka serial oleh rangeToArray(), bukan string "DD-MM-YYYY").
    // ──────────────────────────────────────────────────────────────

    public function test_import_date_menerima_format_dd_mm_yyyy(): void
    {
        $this->assertSame('2026-06-01', $this->invoke('importDate', '01-06-2026'));
    }

    public function test_import_date_menerima_excel_serial_number(): void
    {
        $serial   = 46000; // cell bertipe Date asli terbaca sebagai serial number oleh rangeToArray()
        $expected = PhpSpreadsheetDate::excelToDateTimeObject($serial)->format('Y-m-d');

        $this->assertSame($expected, $this->invoke('importDate', (string) $serial));
    }

    public function test_import_date_string_bukan_tanggal_mengembalikan_null(): void
    {
        $this->assertNull($this->invoke('importDate', 'bukan-tanggal'));
    }

    public function test_import_date_menerima_nama_bulan_indonesia(): void
    {
        $this->assertSame('2026-05-28', $this->invoke('importDate', '28 Mei 2026'));
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
            $row = ['Klien Satu', '01-06-2026', '', '', '', '', '', '', '', 'Barang A', $code, 'pcs', '1000', 'B2B'];

            $message = $this->invoke('detectFormulaError', $row, 'Data Sheet', 15);

            $this->assertNotNull($message, "Kode error {$code} harus terdeteksi.");
            $this->assertStringContainsString('Baris 15', $message);
            $this->assertStringContainsString($code, $message);
            $this->assertStringContainsString('rumus Excel', $message);
        }
    }

    public function test_detect_formula_error_null_untuk_baris_normal(): void
    {
        $row = ['Klien Satu', '01-06-2026', '', '', '', '', '', '', '', 'Barang A', '5', 'pcs', '1000', 'B2B'];

        $this->assertNull($this->invoke('detectFormulaError', $row, 'Data Sheet', 15));
    }

    public function test_detect_formula_error_tidak_false_positive_pada_teks_biasa_berisi_pagar(): void
    {
        $row = ['Klien Satu', '01-06-2026', '', '', '', '', '', '', '', 'Harga #1 termurah', '5', 'pcs', '1000', 'B2B'];

        $this->assertNull(
            $this->invoke('detectFormulaError', $row, 'Data Sheet', 15),
            'Teks biasa yang kebetulan mengandung "#" tidak boleh dianggap kode error Excel.',
        );
    }

    public function test_detect_formula_error_melaporkan_huruf_kolom_yang_akurat(): void
    {
        // Index 10 (0-based) = kolom qty = kolom K di file fisik (A=0, B=1, ..., K=10).
        $row = array_fill(0, 14, '');
        $row[10] = '#DIV/0!';

        $message = $this->invoke('detectFormulaError', $row, 'Data Sheet', 20);

        $this->assertStringContainsString('kolom K', $message, 'Huruf kolom harus cocok dengan posisi fisik di file Excel (index 10 = kolom K).');
        $this->assertStringNotContainsString('kolom J', $message);
        $this->assertStringNotContainsString('kolom L', $message);
    }
}
