<?php

namespace Tests\Feature;

use App\Domain\Finance\PembayaranAp\Services\PembayaranApService;
use App\Domain\Finance\PembayaranAr\Services\PembayaranArService;
use App\Domain\Finance\RekonsiliasiBankStatement\Services\BankStatementService;
use App\Models\BankStatementDetail;
use App\Models\PembayaranAp;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Unit test untuk validasi BankStatementService::matchWithNewVoucherAp() —
 * hanya menguji jalur yang gagal SEBELUM DB::transaction() dijalankan (murni
 * in-memory, forceFill, tanpa DB) plus 1 skenario "lolos validasi" yang
 * membuktikan createVoucher() terpanggil dengan payload benar sebelum
 * transaksi menyentuh tabel yang memang tidak ter-migrasi di DB test
 * (:memory: sqlite, migrate:fresh rusak di project ini — lihat
 * tests/Feature/MasterImportServiceTest.php). Skenario itu SENGAJA
 * membiarkan exception dari lapisan DB menembus try/catch, karena tujuannya
 * cuma membuktikan validasi toleransi 0,01 lolos dan payload yang dikirim ke
 * PembayaranApService::createVoucher() benar — bukan menguji hasil akhir
 * di DB.
 */
class BankStatementServiceVoucherApValidationTest extends TestCase
{
    private function buildService(?PembayaranApService $pembayaranApService = null): BankStatementService
    {
        return new BankStatementService(
            $this->createMock(PembayaranArService::class),
            $pembayaranApService ?? $this->createMock(PembayaranApService::class),
        );
    }

    private function fakeDetail(float $debit, string $statusCocok, float $kredit = 0): BankStatementDetail
    {
        $detail = new BankStatementDetail();
        $detail->forceFill([
            'id'                => 1,
            'bank_statement_id' => 1,
            'tanggal'           => '2026-07-20',
            'keterangan'        => 'Transfer keluar vendor',
            'no_referensi'      => null,
            'debit'             => $debit,
            'kredit'            => $kredit,
            'saldo'             => 0,
            'status_cocok'      => $statusCocok,
        ]);

        return $detail;
    }

    public function test_tolak_jika_detail_sudah_matched(): void
    {
        $pembayaranApService = $this->createMock(PembayaranApService::class);
        $pembayaranApService->expects($this->never())->method('createVoucher');

        $detail  = $this->fakeDetail(debit: 500000, statusCocok: 'MATCHED');
        $service = $this->buildService($pembayaranApService);

        try {
            $service->matchWithNewVoucherAp($detail, [['tagihan_ap_id' => 1, 'jumlah' => 500000]], 'BB');
            $this->fail('Seharusnya melempar HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('Transaksi ini sudah dicocokkan.', $e->getMessage());
        }
    }

    public function test_tolak_jika_bukan_transaksi_debit(): void
    {
        $pembayaranApService = $this->createMock(PembayaranApService::class);
        $pembayaranApService->expects($this->never())->method('createVoucher');

        $detail  = $this->fakeDetail(debit: 0, statusCocok: 'UNMATCHED', kredit: 500000);
        $service = $this->buildService($pembayaranApService);

        try {
            $service->matchWithNewVoucherAp($detail, [['tagihan_ap_id' => 1, 'jumlah' => 500000]], 'BB');
            $this->fail('Seharusnya melempar HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('Hanya transaksi debit', $e->getMessage());
        }
    }

    public function test_tolak_jika_alokasi_kosong(): void
    {
        $pembayaranApService = $this->createMock(PembayaranApService::class);
        $pembayaranApService->expects($this->never())->method('createVoucher');

        $detail  = $this->fakeDetail(debit: 500000, statusCocok: 'UNMATCHED');
        $service = $this->buildService($pembayaranApService);

        try {
            $service->matchWithNewVoucherAp($detail, [], 'BB');
            $this->fail('Seharusnya melempar HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('minimal 1 tagihan', $e->getMessage());
        }
    }

    public function test_tolak_jika_total_alokasi_tidak_sama_dengan_debit(): void
    {
        $pembayaranApService = $this->createMock(PembayaranApService::class);
        $pembayaranApService->expects($this->never())->method('createVoucher');

        $detail  = $this->fakeDetail(debit: 500000, statusCocok: 'UNMATCHED');
        $service = $this->buildService($pembayaranApService);

        try {
            // Selisih Rp 1.000 — jauh di atas toleransi 0,01.
            $service->matchWithNewVoucherAp($detail, [['tagihan_ap_id' => 1, 'jumlah' => 499000]], 'BB');
            $this->fail('Seharusnya melempar HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('Total alokasi', $e->getMessage());
        }
    }

    public function test_lolos_validasi_ketika_selisih_dalam_toleransi_0_01(): void
    {
        $fakeVoucher = new PembayaranAp();
        $fakeVoucher->forceFill(['id' => 55]);
        $fakeVoucher->setRelation('items', collect());

        $pembayaranApService = $this->createMock(PembayaranApService::class);
        $pembayaranApService->expects($this->once())
            ->method('createVoucher')
            ->with($this->callback(function (array $data) {
                return $data['dibuat_dari_rekonsiliasi'] === true
                    && $data['metode_pembayaran'] === 'TRANSFER'
                    && $data['kategori_voucher'] === 'BB'
                    && $data['alokasi'][0]['tagihan_ap_id'] === 1
                    && abs($data['alokasi'][0]['jumlah'] - 499999.99) < 0.001;
            }), null)
            ->willReturn($fakeVoucher);

        $detail  = $this->fakeDetail(debit: 500000, statusCocok: 'UNMATCHED');
        $service = $this->buildService($pembayaranApService);

        try {
            // Selisih Rp 0,01 — masih dalam toleransi, validasi harus LOLOS dan
            // createVoucher() harus terpanggil (diverifikasi oleh mock di atas)
            // sebelum DB::transaction() gagal karena skema test tidak ter-migrasi.
            $service->matchWithNewVoucherAp($detail, [['tagihan_ap_id' => 1, 'jumlah' => 499999.99]], 'BB');
            $this->fail('Seharusnya melempar exception dari lapisan DB (skema test tidak tersedia).');
        } catch (HttpException $e) {
            $this->fail('Validasi semestinya lolos untuk selisih Rp 0,01, tapi malah ditolak: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // Diharapkan: query gagal karena tb_bank_statement_detail tidak ada
            // di DB test (:memory: sqlite, tanpa migrasi). Ini membuktikan alur
            // sudah melewati semua validasi dan sampai ke DB::transaction().
        }
    }
}
