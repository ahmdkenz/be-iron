<?php

namespace Tests\Feature;

use App\Domain\Finance\PembayaranAp\Services\PembayaranApService;
use App\Domain\Finance\TagihanAp\Services\TagihanApService;
use Tests\TestCase;

/**
 * Unit test untuk generator kode voucher Payment Voucher AP.
 * Menggunakan reflection agar method privat bisa diakses tanpa mengubah
 * visibility — generateKodeVoucher() sendiri menyentuh DB (cek duplikat),
 * jadi yang diuji di sini adalah buildKodeVoucherCandidate() yang murni
 * (lihat tests/Feature/MasterImportServiceTest.php untuk pola yang sama,
 * dan project_test_db_migrate_fresh_broken di memory untuk alasan RefreshDatabase
 * tidak dipakai).
 */
class PembayaranApServiceKodeVoucherTest extends TestCase
{
    private PembayaranApService $service;
    private \ReflectionClass $ref;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PembayaranApService($this->createMock(TagihanApService::class));
        $this->ref     = new \ReflectionClass($this->service);
    }

    private function buildCandidate(string $kategoriVoucher, string $tanggalPembayaran): string
    {
        $m = $this->ref->getMethod('buildKodeVoucherCandidate');
        $m->setAccessible(true);

        return $m->invoke($this->service, $kategoriVoucher, $tanggalPembayaran);
    }

    public function test_format_kode_voucher_bahan_baku(): void
    {
        $kode = $this->buildCandidate('BB', '2026-07-20');

        $this->assertMatchesRegularExpression('/^VBK-BB-20072026-\d{3}$/', $kode);
    }

    public function test_format_kode_voucher_non_bahan_baku(): void
    {
        $kode = $this->buildCandidate('NBB', '2026-07-20');

        $this->assertMatchesRegularExpression('/^VBK-NBB-20072026-\d{3}$/', $kode);
    }

    public function test_suffix_selalu_3_digit_zero_padded(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $kode   = $this->buildCandidate('BB', '2026-01-05');
            $suffix = substr($kode, -3);

            $this->assertMatchesRegularExpression('/^\d{3}$/', $suffix);
        }
    }

    public function test_tanggal_pembayaran_menentukan_segmen_tanggal_kode(): void
    {
        $kode = $this->buildCandidate('NBB', '2026-12-01');

        $this->assertStringContainsString('-01122026-', $kode);
    }
}
