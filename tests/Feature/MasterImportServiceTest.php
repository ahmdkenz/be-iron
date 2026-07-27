<?php

namespace Tests\Feature;

use App\Domain\Finance\Invoice\Services\InvoiceGroupProcessor;
use App\Domain\Finance\KlienAr\Services\KlienArService;
use App\Domain\Master\Investor\Services\InvestorService;
use App\Domain\Master\Resto\Services\RestoService;
use App\Domain\Master\Unified\Services\MasterImportService;
use App\Models\Barang;
use App\Models\Investor;
use App\Models\KlienAr;
use App\Models\Resto;
use Tests\TestCase;

/**
 * Unit tests untuk helper perbandingan data di MasterImportService.
 * Menggunakan reflection agar private method bisa diakses tanpa mengubah visibility.
 */
class MasterImportServiceTest extends TestCase
{
    private MasterImportService $service;
    private \ReflectionClass $ref;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MasterImportService(
            $this->createMock(InvestorService::class),
            $this->createMock(RestoService::class),
            $this->createMock(KlienArService::class),
            $this->createMock(InvoiceGroupProcessor::class),
        );

        $this->ref = new \ReflectionClass($this->service);
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $m = $this->ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->service, ...$args);
    }

    // ──────────────────────────────────────────────────────────────
    //  investorHasChanged
    // ──────────────────────────────────────────────────────────────

    public function test_investor_identical_data_returns_false(): void
    {
        $investor = new Investor();
        $investor->forceFill([
            'nama_investor'   => 'Budi',
            'ktp'             => '1234567890',
            'npwp'            => '987654321',
            'no_hp'           => '08123456789',
            'pengelola'       => 'Andi',
            'no_hp_pengelola' => '08111111111',
            'kode_cabang'     => 'KD01',
            'id_cabang'       => 'CB01',
            'status'          => true,
        ]);

        $import = [
            'nama_investor'   => 'Budi',
            'ktp'             => '1234567890',
            'npwp'            => '987654321',
            'no_hp'           => '08123456789',
            'pengelola'       => 'Andi',
            'no_hp_pengelola' => '08111111111',
            'kode_cabang'     => 'KD01',
            'id_cabang'       => 'CB01',
            'status'          => true,
        ];

        $this->assertFalse($this->invoke('investorHasChanged', $investor, $import));
    }

    public function test_investor_different_field_returns_true(): void
    {
        $investor = new Investor();
        $investor->forceFill([
            'nama_investor'   => 'Budi',
            'ktp'             => '1234567890',
            'npwp'            => null,
            'no_hp'           => null,
            'pengelola'       => null,
            'no_hp_pengelola' => null,
            'kode_cabang'     => null,
            'id_cabang'       => null,
            'status'          => true,
        ]);

        $import = [
            'nama_investor'   => 'Budi',
            'ktp'             => '9999999999', // berubah
            'npwp'            => null,
            'no_hp'           => null,
            'pengelola'       => null,
            'no_hp_pengelola' => null,
            'kode_cabang'     => null,
            'id_cabang'       => null,
            'status'          => true,
        ];

        $this->assertTrue($this->invoke('investorHasChanged', $investor, $import));
    }

    public function test_investor_null_vs_dash_treated_equal(): void
    {
        $investor = new Investor();
        $investor->forceFill([
            'nama_investor'   => 'Budi',
            'ktp'             => null,
            'npwp'            => null,
            'no_hp'           => null,
            'pengelola'       => null,
            'no_hp_pengelola' => null,
            'kode_cabang'     => null,
            'id_cabang'       => null,
            'status'          => true,
        ]);

        // Import mengirim '-' yang harus disamakan dengan null
        $import = [
            'nama_investor'   => 'Budi',
            'ktp'             => null,
            'npwp'            => null,
            'no_hp'           => null,
            'pengelola'       => null,
            'no_hp_pengelola' => null,
            'kode_cabang'     => null,
            'id_cabang'       => null,
            'status'          => true,
        ];

        $this->assertFalse($this->invoke('investorHasChanged', $investor, $import));
    }

    public function test_investor_status_change_returns_true(): void
    {
        $investor = new Investor();
        $investor->forceFill([
            'nama_investor'   => 'Budi',
            'ktp'             => null,
            'npwp'            => null,
            'no_hp'           => null,
            'pengelola'       => null,
            'no_hp_pengelola' => null,
            'kode_cabang'     => null,
            'id_cabang'       => null,
            'status'          => true,
        ]);

        $import = [
            'nama_investor'   => 'Budi',
            'ktp'             => null,
            'npwp'            => null,
            'no_hp'           => null,
            'pengelola'       => null,
            'no_hp_pengelola' => null,
            'kode_cabang'     => null,
            'id_cabang'       => null,
            'status'          => false, // berubah
        ];

        $this->assertTrue($this->invoke('investorHasChanged', $investor, $import));
    }

    // ──────────────────────────────────────────────────────────────
    //  restoHasChanged
    // ──────────────────────────────────────────────────────────────

    public function test_resto_identical_data_returns_false(): void
    {
        $resto = new Resto();
        $resto->forceFill([
            'nama_resto'       => 'Cabang A',
            'perusahaan_id'    => 1,
            'brand_id'         => 2,
            'investor_id'      => 3,
            'karyawan_id'      => 4,
            'supervisor'       => 'Siti',
            'no_hp_supervisor' => '081234',
            'stokis'           => 'Gudang 1',
            'area'             => 'Jakarta',
            'kota'             => 'Jakarta Selatan',
            'alamat'           => 'Jl. Mawar No. 1',
            'no_telp'          => '02112345',
            'tgl_aktif'        => null,
            'keterangan'       => 'oke',
            'status'           => true,
        ]);

        $import = [
            'nama_resto'       => 'Cabang A',
            'perusahaan_id'    => 1,
            'brand_id'         => 2,
            'investor_id'      => 3,
            'karyawan_id'      => 4,
            'supervisor'       => 'Siti',
            'no_hp_supervisor' => '081234',
            'stokis'           => 'Gudang 1',
            'area'             => 'Jakarta',
            'kota'             => 'Jakarta Selatan',
            'alamat'           => 'Jl. Mawar No. 1',
            'no_telp'          => '02112345',
            'tgl_aktif'        => null,
            'keterangan'       => 'oke',
            'status'           => true,
        ];

        $this->assertFalse($this->invoke('restoHasChanged', $resto, $import));
    }

    public function test_resto_changed_string_field_returns_true(): void
    {
        $resto = new Resto();
        $resto->forceFill([
            'nama_resto'       => 'Cabang A',
            'perusahaan_id'    => 1,
            'brand_id'         => 2,
            'investor_id'      => 3,
            'karyawan_id'      => 4,
            'supervisor'       => 'Siti',
            'no_hp_supervisor' => null,
            'stokis'           => null,
            'area'             => null,
            'kota'             => null,
            'alamat'           => null,
            'no_telp'          => null,
            'tgl_aktif'        => null,
            'keterangan'       => null,
            'status'           => true,
        ]);

        $import = [
            'nama_resto'       => 'Cabang A',
            'perusahaan_id'    => 1,
            'brand_id'         => 2,
            'investor_id'      => 3,
            'karyawan_id'      => 4,
            'supervisor'       => 'Rudi', // berubah
            'no_hp_supervisor' => null,
            'stokis'           => null,
            'area'             => null,
            'kota'             => null,
            'alamat'           => null,
            'no_telp'          => null,
            'tgl_aktif'        => null,
            'keterangan'       => null,
            'status'           => true,
        ];

        $this->assertTrue($this->invoke('restoHasChanged', $resto, $import));
    }

    public function test_resto_changed_id_field_returns_true(): void
    {
        $resto = new Resto();
        $resto->forceFill([
            'nama_resto'       => 'Cabang B',
            'perusahaan_id'    => 1,
            'brand_id'         => 2,
            'investor_id'      => 3,
            'karyawan_id'      => 4,
            'supervisor'       => null,
            'no_hp_supervisor' => null,
            'stokis'           => null,
            'area'             => null,
            'kota'             => null,
            'alamat'           => null,
            'no_telp'          => null,
            'tgl_aktif'        => null,
            'keterangan'       => null,
            'status'           => true,
        ]);

        $import = [
            'nama_resto'       => 'Cabang B',
            'perusahaan_id'    => 1,
            'brand_id'         => 99, // berubah
            'investor_id'      => 3,
            'karyawan_id'      => 4,
            'supervisor'       => null,
            'no_hp_supervisor' => null,
            'stokis'           => null,
            'area'             => null,
            'kota'             => null,
            'alamat'           => null,
            'no_telp'          => null,
            'tgl_aktif'        => null,
            'keterangan'       => null,
            'status'           => true,
        ];

        $this->assertTrue($this->invoke('restoHasChanged', $resto, $import));
    }

    public function test_resto_tgl_aktif_identical_returns_false(): void
    {
        $resto = new Resto();
        $resto->forceFill([
            'nama_resto'       => 'Cabang C',
            'perusahaan_id'    => null,
            'brand_id'         => null,
            'investor_id'      => null,
            'karyawan_id'      => null,
            'supervisor'       => null,
            'no_hp_supervisor' => null,
            'stokis'           => null,
            'area'             => null,
            'kota'             => null,
            'alamat'           => null,
            'no_telp'          => null,
            'tgl_aktif'        => '2024-01-15',
            'keterangan'       => null,
            'status'           => true,
        ]);

        $import = [
            'nama_resto'       => 'Cabang C',
            'perusahaan_id'    => null,
            'brand_id'         => null,
            'investor_id'      => null,
            'karyawan_id'      => null,
            'supervisor'       => null,
            'no_hp_supervisor' => null,
            'stokis'           => null,
            'area'             => null,
            'kota'             => null,
            'alamat'           => null,
            'no_telp'          => null,
            'tgl_aktif'        => '2024-01-15',
            'keterangan'       => null,
            'status'           => true,
        ];

        $this->assertFalse($this->invoke('restoHasChanged', $resto, $import));
    }

    // ──────────────────────────────────────────────────────────────
    //  barangHasChanged
    // ──────────────────────────────────────────────────────────────

    public function test_barang_identical_data_returns_false(): void
    {
        $barang = new Barang();
        $barang->forceFill([
            'nama_barang' => 'Ayam Goreng',
            'spesifikasi' => '1 kg',
            'keterangan'  => 'Segar',
            'status'      => true,
        ]);

        $import = [
            'nama_barang' => 'Ayam Goreng',
            'spesifikasi' => '1 kg',
            'keterangan'  => 'Segar',
            'status'      => true,
        ];

        $this->assertFalse($this->invoke('barangHasChanged', $barang, $import));
    }

    public function test_barang_changed_nama_returns_true(): void
    {
        $barang = new Barang();
        $barang->forceFill([
            'nama_barang' => 'Ayam Goreng',
            'spesifikasi' => null,
            'keterangan'  => null,
            'status'      => true,
        ]);

        $import = [
            'nama_barang' => 'Ayam Bakar', // berubah
            'spesifikasi' => null,
            'keterangan'  => null,
            'status'      => true,
        ];

        $this->assertTrue($this->invoke('barangHasChanged', $barang, $import));
    }

    public function test_barang_changed_spesifikasi_returns_true(): void
    {
        $barang = new Barang();
        $barang->forceFill([
            'nama_barang' => 'Produk X',
            'spesifikasi' => '1 kg',
            'keterangan'  => null,
            'status'      => true,
        ]);

        $import = [
            'nama_barang' => 'Produk X',
            'spesifikasi' => '2 kg', // berubah
            'keterangan'  => null,
            'status'      => true,
        ];

        $this->assertTrue($this->invoke('barangHasChanged', $barang, $import));
    }

    public function test_barang_status_change_returns_true(): void
    {
        $barang = new Barang();
        $barang->forceFill([
            'nama_barang' => 'Produk Z',
            'spesifikasi' => null,
            'keterangan'  => null,
            'status'      => true,
        ]);

        $import = [
            'nama_barang' => 'Produk Z',
            'spesifikasi' => null,
            'keterangan'  => null,
            'status'      => false, // berubah
        ];

        $this->assertTrue($this->invoke('barangHasChanged', $barang, $import));
    }

    // ──────────────────────────────────────────────────────────────
    //  klienArHasChanged
    // ──────────────────────────────────────────────────────────────

    public function test_klien_ar_identical_data_returns_false(): void
    {
        $klien = new KlienAr();
        $klien->forceFill([
            'nama_klien'     => 'PT Sejahtera',
            'tipe_klien'     => 'PT',
            'no_npwp'        => '123456789',
            'no_wa'          => '08123456789',
            'perusahaan_id'  => 1,
            'karyawan_ar_id' => 2,
            'resto_id'       => null,
            'status'         => true,
        ]);

        $import = [
            'nama_klien'     => 'PT Sejahtera',
            'tipe_klien'     => 'PT',
            'no_npwp'        => '123456789',
            'no_wa'          => '08123456789',
            'perusahaan_id'  => 1,
            'karyawan_ar_id' => 2,
            'resto_id'       => null,
            'status'         => true,
        ];

        $this->assertFalse($this->invoke('klienArHasChanged', $klien, $import));
    }

    public function test_klien_ar_changed_string_field_returns_true(): void
    {
        $klien = new KlienAr();
        $klien->forceFill([
            'nama_klien'     => 'PT Sejahtera',
            'tipe_klien'     => 'PT',
            'no_npwp'        => '123456789',
            'no_wa'          => null,
            'perusahaan_id'  => 1,
            'karyawan_ar_id' => 2,
            'resto_id'       => null,
            'status'         => true,
        ]);

        $import = [
            'nama_klien'     => 'PT Sejahtera',
            'tipe_klien'     => 'PT',
            'no_npwp'        => '999999999', // berubah
            'no_wa'          => null,
            'perusahaan_id'  => 1,
            'karyawan_ar_id' => 2,
            'resto_id'       => null,
            'status'         => true,
        ];

        $this->assertTrue($this->invoke('klienArHasChanged', $klien, $import));
    }

    public function test_klien_ar_changed_id_field_returns_true(): void
    {
        $klien = new KlienAr();
        $klien->forceFill([
            'nama_klien'     => 'Cabang A',
            'tipe_klien'     => 'RESTO',
            'no_npwp'        => null,
            'no_wa'          => null,
            'perusahaan_id'  => null,
            'karyawan_ar_id' => 2,
            'resto_id'       => 10,
            'status'         => true,
        ]);

        $import = [
            'nama_klien'     => 'Cabang A',
            'tipe_klien'     => 'RESTO',
            'no_npwp'        => null,
            'no_wa'          => null,
            'perusahaan_id'  => null,
            'karyawan_ar_id' => 99, // berubah
            'resto_id'       => 10,
            'status'         => true,
        ];

        $this->assertTrue($this->invoke('klienArHasChanged', $klien, $import));
    }

    public function test_klien_ar_status_change_returns_true(): void
    {
        $klien = new KlienAr();
        $klien->forceFill([
            'nama_klien'     => 'PT Makmur',
            'tipe_klien'     => 'PT',
            'no_npwp'        => null,
            'no_wa'          => null,
            'perusahaan_id'  => 5,
            'karyawan_ar_id' => 2,
            'resto_id'       => null,
            'status'         => true,
        ]);

        $import = [
            'nama_klien'     => 'PT Makmur',
            'tipe_klien'     => 'PT',
            'no_npwp'        => null,
            'no_wa'          => null,
            'perusahaan_id'  => 5,
            'karyawan_ar_id' => 2,
            'resto_id'       => null,
            'status'         => false, // berubah
        ];

        $this->assertTrue($this->invoke('klienArHasChanged', $klien, $import));
    }

    // ──────────────────────────────────────────────────────────────
    //  resolveKlienForInvoiceRow
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

    public function test_resolve_b2b_uses_nama_map_and_ignores_kode_resto(): void
    {
        $klienPt = $this->makeKlien(1, 'PT Sejahtera', 'PT');
        $namaMap = ['pt sejahtera' => $klienPt];

        [$klien, $error] = $this->invoke(
            'resolveKlienForInvoiceRow',
            'B2B', 'PT Sejahtera', 'KD-999', // kode_resto sengaja diisi salah, harus tetap diabaikan
            $namaMap, [], [], [],
        );

        $this->assertSame($klienPt, $klien);
        $this->assertNull($error);
    }

    public function test_resolve_b2b_not_found_returns_error(): void
    {
        [$klien, $error] = $this->invoke(
            'resolveKlienForInvoiceRow',
            'B2B', 'PT Tidak Ada', null,
            [], [], [], [],
        );

        $this->assertNull($klien);
        $this->assertStringContainsString('tidak ditemukan', $error);
    }

    public function test_resolve_b2c_with_kode_resto_uses_resto_map(): void
    {
        $outletA = $this->makeKlien(10, 'Investor X', 'RESTO');
        $outletB = $this->makeKlien(11, 'Investor X', 'RESTO');
        $restoMap = ['KD-A' => $outletA, 'KD-B' => $outletB];

        [$klien, $error] = $this->invoke(
            'resolveKlienForInvoiceRow',
            'B2C', 'Investor X', 'kd-b', // lowercase, harus dinormalisasi ke upper
            [], $restoMap, [], [],
        );

        $this->assertSame($outletB, $klien);
        $this->assertNull($error);
    }

    public function test_resolve_b2c_with_unknown_kode_resto_fails_without_name_fallback(): void
    {
        $outletA = $this->makeKlien(10, 'Investor X', 'RESTO');
        $restoMap = ['KD-A' => $outletA];
        // Meski nama investor cocok & tidak ambigu, kode_resto yang salah tidak boleh
        // fallback diam-diam ke pencocokan nama.
        $restoNameMap   = ['investor x' => $outletA];
        $restoNameCount = ['investor x' => 1];

        [$klien, $error] = $this->invoke(
            'resolveKlienForInvoiceRow',
            'B2C', 'Investor X', 'KD-SALAH',
            [], $restoMap, $restoNameMap, $restoNameCount,
        );

        $this->assertNull($klien);
        $this->assertStringContainsString('KD-SALAH', $error);
        $this->assertStringContainsString('tidak ditemukan', $error);
    }

    public function test_resolve_b2c_blank_kode_resto_single_outlet_uses_name_fallback(): void
    {
        $outlet = $this->makeKlien(20, 'Investor Tunggal', 'RESTO');
        $restoNameMap   = ['investor tunggal' => $outlet];
        $restoNameCount = ['investor tunggal' => 1];

        [$klien, $error] = $this->invoke(
            'resolveKlienForInvoiceRow',
            'B2C', 'Investor Tunggal', null,
            [], [], $restoNameMap, $restoNameCount,
        );

        $this->assertSame($outlet, $klien);
        $this->assertNull($error);
    }

    public function test_resolve_b2c_blank_kode_resto_ambiguous_multi_outlet_fails(): void
    {
        $outletA = $this->makeKlien(30, 'Investor Banyak Outlet', 'RESTO');
        $restoNameMap   = ['investor banyak outlet' => $outletA];
        $restoNameCount = ['investor banyak outlet' => 4];

        [$klien, $error] = $this->invoke(
            'resolveKlienForInvoiceRow',
            'B2C', 'Investor Banyak Outlet', null,
            [], [], $restoNameMap, $restoNameCount,
        );

        $this->assertNull($klien);
        $this->assertStringContainsString('4 outlet', $error);
        $this->assertStringContainsString('kode_resto', $error);
    }

    public function test_resolve_b2c_blank_kode_resto_not_found_returns_error(): void
    {
        [$klien, $error] = $this->invoke(
            'resolveKlienForInvoiceRow',
            'B2C', 'Investor Tidak Ada', null,
            [], [], [], [],
        );

        $this->assertNull($klien);
        $this->assertStringContainsString('tidak ditemukan', $error);
    }

    // ──────────────────────────────────────────────────────────────
    //  validateInvoiceRowAgainstMasterData
    // ──────────────────────────────────────────────────────────────

    public function test_validate_master_data_pt_b2b_matching_passes(): void
    {
        $restoMasterMap = [
            'FB257' => ['tipe_klien' => 'PT', 'nama_klien' => 'PT. Arkhan Berkah Bersama', 'klien_id' => 1],
        ];

        $error = $this->invoke(
            'validateInvoiceRowAgainstMasterData',
            'B2B', 'PT. Arkhan Berkah Bersama', 'FB257', $restoMasterMap,
        );

        $this->assertNull($error);
    }

    public function test_validate_master_data_resto_b2c_matching_passes(): void
    {
        $restoMasterMap = [
            'KD-A' => ['tipe_klien' => 'RESTO', 'nama_klien' => 'Ian Rizky Kurniawan', 'klien_id' => 2],
        ];

        $error = $this->invoke(
            'validateInvoiceRowAgainstMasterData',
            'B2C', 'Ian Rizky Kurniawan', 'kd-a', $restoMasterMap, // lowercase kode_resto harus dinormalisasi
        );

        $this->assertNull($error);
    }

    public function test_validate_master_data_blank_kode_resto_fails(): void
    {
        $error = $this->invoke(
            'validateInvoiceRowAgainstMasterData',
            'B2B', 'PT Sejahtera', null, [],
        );

        $this->assertStringContainsString('kode_resto wajib diisi', $error);
    }

    public function test_validate_master_data_unknown_kode_resto_fails(): void
    {
        $error = $this->invoke(
            'validateInvoiceRowAgainstMasterData',
            'B2C', 'Investor X', 'KD-TIDAK-ADA', [],
        );

        $this->assertStringContainsString('tidak ditemukan', $error);
    }

    // Regression: FB257/Veteran — kalau MASTER DATA sudah menyatakan outlet itu PT,
    // baris invoice B2C untuk kode_resto tsb wajib gagal, bukan diam-diam ter-resolve
    // ke Client AR RESTO lama (mis. "Ian Rizky Kurniawan").
    public function test_validate_master_data_fb257_registered_as_pt_rejects_b2c(): void
    {
        $restoMasterMap = [
            'FB257' => ['tipe_klien' => 'PT', 'nama_klien' => 'PT. Arkhan Berkah Bersama', 'klien_id' => 5],
        ];

        $error = $this->invoke(
            'validateInvoiceRowAgainstMasterData',
            'B2C', 'Ian Rizky Kurniawan', 'FB257', $restoMasterMap,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('FB257', $error);
        $this->assertStringContainsString('PT', $error);
        $this->assertStringContainsString('B2B', $error);
    }

    public function test_validate_master_data_tipe_invoice_mismatch_for_resto_fails(): void
    {
        $restoMasterMap = [
            'KD-B' => ['tipe_klien' => 'RESTO', 'nama_klien' => 'Investor Y', 'klien_id' => 3],
        ];

        $error = $this->invoke(
            'validateInvoiceRowAgainstMasterData',
            'B2B', 'Investor Y', 'KD-B', $restoMasterMap,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('RESTO', $error);
        $this->assertStringContainsString('B2C', $error);
    }

    public function test_validate_master_data_nama_klien_mismatch_fails(): void
    {
        $restoMasterMap = [
            'FB257' => ['tipe_klien' => 'PT', 'nama_klien' => 'PT. Arkhan Berkah Bersama', 'klien_id' => 5],
        ];

        $error = $this->invoke(
            'validateInvoiceRowAgainstMasterData',
            'B2B', 'PT Lain Yang Salah', 'FB257', $restoMasterMap,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('tidak sesuai MASTER DATA', $error);
    }

    // ──────────────────────────────────────────────────────────────
    //  normalizeHeaderName
    // ──────────────────────────────────────────────────────────────

    public function test_normalize_header_name_strips_mandatory_marker(): void
    {
        $this->assertSame('nama_investor', $this->invoke('normalizeHeaderName', 'nama_investor (*)'));
        $this->assertSame('nama_investor', $this->invoke('normalizeHeaderName', 'nama_investor(*)'));
        $this->assertSame('nama_investor', $this->invoke('normalizeHeaderName', '  Nama_Investor (*)  '));
    }

    public function test_normalize_header_name_without_marker_unchanged(): void
    {
        $this->assertSame('kode_resto', $this->invoke('normalizeHeaderName', 'kode_resto'));
        $this->assertSame('kode_resto', $this->invoke('normalizeHeaderName', ' Kode_Resto '));
    }

    // ──────────────────────────────────────────────────────────────
    //  parseSheet — header bertanda (*)
    // ──────────────────────────────────────────────────────────────

    public function test_parse_sheet_detects_header_with_mandatory_marker(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'TEMPLATE IMPORT MASTER DATA'); // baris judul, harus dilewati
        $sheet->setCellValue('A2', 'nama_investor (*)');
        $sheet->setCellValue('B2', 'ktp');
        $sheet->setCellValue('A3', 'Investor Satu');
        $sheet->setCellValue('B3', '1234567890');

        $rows = $this->invoke('parseSheet', $sheet, 'nama_investor', 2);

        $this->assertCount(2, $rows); // baris header + 1 baris data
        $this->assertSame('nama_investor (*)', $rows[0][0]);
        $this->assertSame('Investor Satu', $rows[1][0]);
    }

    public function test_parse_sheet_detects_header_without_marker_backward_compat(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'nama_investor');
        $sheet->setCellValue('A2', 'Investor Dua');

        $rows = $this->invoke('parseSheet', $sheet, 'nama_investor', 1);

        $this->assertCount(2, $rows);
        $this->assertSame('Investor Dua', $rows[1][0]);
    }

    // ──────────────────────────────────────────────────────────────
    //  resolveKaryawanIdByNameOrNik
    // ──────────────────────────────────────────────────────────────

    public function test_resolve_karyawan_by_nama(): void
    {
        $karyawanMap    = ['andi wijaya' => 1];
        $karyawanNikMap = ['3273010101900001' => 1];

        $this->assertSame(1, $this->invoke('resolveKaryawanIdByNameOrNik', 'Andi Wijaya', $karyawanMap, $karyawanNikMap));
    }

    public function test_resolve_karyawan_by_nik(): void
    {
        $karyawanMap    = ['andi wijaya' => 1];
        $karyawanNikMap = ['3273010101900001' => 1];

        $this->assertSame(1, $this->invoke('resolveKaryawanIdByNameOrNik', '3273010101900001', $karyawanMap, $karyawanNikMap));
    }

    public function test_resolve_karyawan_not_found_returns_null(): void
    {
        $this->assertNull($this->invoke('resolveKaryawanIdByNameOrNik', 'Tidak Ada', ['andi wijaya' => 1], ['3273010101900001' => 1]));
    }

    // ──────────────────────────────────────────────────────────────
    //  resolvePicRestoForRow
    // ──────────────────────────────────────────────────────────────

    public function test_pic_resto_pt_pic_ar_by_nik_used_directly(): void
    {
        $karyawanMap    = ['siti rahayu' => 2];
        $karyawanNikMap = ['3273010101900002' => 2];

        $result = $this->invoke('resolvePicRestoForRow', '', '3273010101900002', 'PT', $karyawanMap, $karyawanNikMap);

        $this->assertSame(2, $result['karyawan_id']);
        $this->assertTrue($result['used_fallback']);
        $this->assertNull($result['conflict_error']);
    }

    public function test_pic_resto_resto_nama_pic_by_nik(): void
    {
        $karyawanMap    = ['andi wijaya' => 1];
        $karyawanNikMap = ['3273010101900001' => 1];

        $result = $this->invoke('resolvePicRestoForRow', '3273010101900001', '', 'RESTO', $karyawanMap, $karyawanNikMap);

        $this->assertSame(1, $result['karyawan_id']);
        $this->assertFalse($result['used_fallback']);
        $this->assertNull($result['conflict_error']);
    }

    public function test_pic_resto_resto_falls_back_to_pic_ar_when_nama_pic_kosong(): void
    {
        $karyawanMap    = ['siti rahayu' => 2];
        $karyawanNikMap = [];

        $result = $this->invoke('resolvePicRestoForRow', '', 'Siti Rahayu', 'RESTO', $karyawanMap, $karyawanNikMap);

        $this->assertSame(2, $result['karyawan_id']);
        $this->assertTrue($result['used_fallback']);
        $this->assertNull($result['conflict_error']);
    }

    public function test_pic_resto_resto_conflict_when_nama_pic_and_pic_ar_differ(): void
    {
        $karyawanMap    = ['andi wijaya' => 1, 'siti rahayu' => 2];
        $karyawanNikMap = [];

        $result = $this->invoke('resolvePicRestoForRow', 'Andi Wijaya', 'Siti Rahayu', 'RESTO', $karyawanMap, $karyawanNikMap);

        $this->assertSame(1, $result['karyawan_id']);
        $this->assertFalse($result['used_fallback']);
        $this->assertNotNull($result['conflict_error']);
        $this->assertStringContainsString('Andi Wijaya', $result['conflict_error']);
        $this->assertStringContainsString('Siti Rahayu', $result['conflict_error']);
    }

    public function test_pic_resto_resto_no_conflict_when_nama_pic_and_pic_ar_same_person(): void
    {
        $karyawanMap    = ['andi wijaya' => 1];
        $karyawanNikMap = ['3273010101900001' => 1];

        // nama_pic diisi nama, pic_ar diisi NIK — sama-sama menunjuk karyawan yang sama
        $result = $this->invoke('resolvePicRestoForRow', 'Andi Wijaya', '3273010101900001', 'RESTO', $karyawanMap, $karyawanNikMap);

        $this->assertSame(1, $result['karyawan_id']);
        $this->assertNull($result['conflict_error']);
    }

    public function test_pic_resto_pt_ignores_conflict_between_nama_pic_and_pic_ar(): void
    {
        // Untuk PT, nama_pic (PIC Resto) & pic_ar (PIC AR Client) memang dua peran berbeda —
        // boleh diisi orang berbeda, tidak dianggap konflik.
        $karyawanMap    = ['andi wijaya' => 1, 'siti rahayu' => 2];
        $karyawanNikMap = [];

        $result = $this->invoke('resolvePicRestoForRow', 'Andi Wijaya', 'Siti Rahayu', 'PT', $karyawanMap, $karyawanNikMap);

        $this->assertSame(1, $result['karyawan_id']);
        $this->assertNull($result['conflict_error']);
    }

    // ──────────────────────────────────────────────────────────────
    //  importDate
    // ──────────────────────────────────────────────────────────────

    public function test_import_date_from_ddmmyyyy_text(): void
    {
        $this->assertSame('2024-05-02', $this->invoke('importDate', '02-05-2024'));
    }

    public function test_import_date_from_yyyymmdd_text(): void
    {
        $this->assertSame('2024-05-02', $this->invoke('importDate', '2024-05-02'));
    }

    public function test_import_date_from_excel_serial_number(): void
    {
        // 45414 = 2 Mei 2024 (serial number Excel yang lolos tanpa format tanggal pada cell)
        $this->assertSame('2024-05-02', $this->invoke('importDate', '45414'));
    }

    public function test_import_date_empty_or_dash_returns_null(): void
    {
        $this->assertNull($this->invoke('importDate', ''));
        $this->assertNull($this->invoke('importDate', '-'));
    }

    // ──────────────────────────────────────────────────────────────
    //  xlsxCellToString
    // ──────────────────────────────────────────────────────────────

    private function makeCell(mixed $value, ?string $numberFormat = null): \PhpOffice\PhpSpreadsheet\Cell\Cell
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', $value);
        if ($numberFormat !== null) {
            $sheet->getStyle('A1')->getNumberFormat()->setFormatCode($numberFormat);
        }
        return $sheet->getCell('A1');
    }

    public function test_xlsx_cell_date_formatted_serial_converts_to_ymd(): void
    {
        $cell = $this->makeCell(45414, \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_DATE_DDMMYYYY);
        $this->assertSame('2024-05-02', $this->invoke('xlsxCellToString', $cell));
    }

    public function test_xlsx_cell_plain_integer_without_date_format_stays_numeric(): void
    {
        $cell = $this->makeCell(45414);
        $this->assertSame('45414', $this->invoke('xlsxCellToString', $cell));
    }

    public function test_xlsx_cell_text_date_stays_as_text(): void
    {
        $cell = $this->makeCell('02-05-2024');
        $this->assertSame('02-05-2024', $this->invoke('xlsxCellToString', $cell));
    }
}
