<?php

namespace Tests\Feature;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceService;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\PembayaranAr\Services\PembayaranArService;
use App\Domain\Finance\PendapatanDiMuka\Services\PendapatanDiMukaService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Unit test untuk guard pra-transaksi PembayaranArService::createMultiPayment()
 * — hanya menguji jalur yang gagal SEBELUM DB::transaction() dijalankan (alokasi
 * kosong, cap 50, invoice_id duplikat), mirip pola guard clause di
 * PembayaranApService::createVoucher() (lihat BankStatementServiceVoucherApValidationTest
 * untuk pola serupa di sisi AP). Skenario "lolos guard" tidak diuji di sini
 * karena langsung menyentuh DB::transaction() + lockForUpdate() yang butuh
 * skema tb_invoice ter-migrasi (lihat project_test_db_migrate_fresh_broken di
 * memory) — verifikasi jalur sukses dilakukan manual di DB dev, dan sekarang juga
 * oleh PembayaranArMultiPaymentInvoiceUpdateTest (schema ad-hoc, DB nyata).
 */
class PembayaranArServiceCreateMultiPaymentTest extends TestCase
{
    private function buildService(): PembayaranArService
    {
        return new PembayaranArService(
            $this->createMock(InvoiceService::class),
            $this->createMock(PendapatanDiMukaService::class),
            $this->createMock(EndingBalanceService::class),
        );
    }

    public function test_tolak_jika_alokasi_kosong(): void
    {
        $service = $this->buildService();

        try {
            $service->createMultiPayment([
                'tanggal_pembayaran' => '2026-07-31',
                'metode_pembayaran'  => 'TRANSFER',
                'alokasi'            => [],
            ]);
            $this->fail('Seharusnya melempar HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('minimal 1 invoice', $e->getMessage());
        }
    }

    public function test_tolak_jika_melebihi_cap_50_invoice(): void
    {
        $service = $this->buildService();

        $alokasi = [];
        for ($i = 1; $i <= 51; $i++) {
            $alokasi[] = ['invoice_id' => $i, 'jumlah' => 1000];
        }

        try {
            $service->createMultiPayment([
                'tanggal_pembayaran' => '2026-07-31',
                'metode_pembayaran'  => 'TRANSFER',
                'alokasi'            => $alokasi,
            ]);
            $this->fail('Seharusnya melempar HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('Maksimum 50 invoice', $e->getMessage());
        }
    }

    public function test_lolos_cap_tepat_50_invoice(): void
    {
        // Tepat 50 baris harus lolos guard cap (baru gagal di lockForUpdate()
        // karena skema test tidak ter-migrasi — itu perilaku yang diharapkan
        // di titik ini, bukan gagal dari guard cap).
        $service = $this->buildService();

        $alokasi = [];
        for ($i = 1; $i <= 50; $i++) {
            $alokasi[] = ['invoice_id' => $i, 'jumlah' => 1000];
        }

        try {
            $service->createMultiPayment([
                'tanggal_pembayaran' => '2026-07-31',
                'metode_pembayaran'  => 'TRANSFER',
                'alokasi'            => $alokasi,
            ]);
            $this->fail('Seharusnya melempar exception dari lapisan DB (skema test tidak tersedia).');
        } catch (HttpException $e) {
            $this->fail('Guard cap semestinya lolos untuk tepat 50 invoice, tapi malah ditolak: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // Diharapkan: query lockForUpdate() gagal karena tb_invoice tidak
            // ter-migrasi di DB test. Membuktikan guard cap sudah dilewati.
            $this->assertNotInstanceOf(HttpException::class, $e);
        }
    }

    public function test_tolak_jika_invoice_id_duplikat(): void
    {
        $service = $this->buildService();

        try {
            $service->createMultiPayment([
                'tanggal_pembayaran' => '2026-07-31',
                'metode_pembayaran'  => 'TRANSFER',
                'alokasi'            => [
                    ['invoice_id' => 5, 'jumlah' => 100000],
                    ['invoice_id' => 5, 'jumlah' => 50000],
                ],
            ]);
            $this->fail('Seharusnya melempar HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('duplikat', $e->getMessage());
        }
    }
}
