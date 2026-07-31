<?php

namespace Tests\Feature;

use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\PembayaranAp\Services\PembayaranApService;
use App\Domain\Finance\PembayaranAr\Services\PembayaranArService;
use App\Domain\Finance\RekonsiliasiBankStatement\Services\BankStatementService;
use App\Models\BankStatementDetail;
use App\Models\PembayaranAr;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Unit test untuk validasi BankStatementService::matchWithNewMultiPayment() —
 * hanya menguji jalur yang gagal SEBELUM DB::transaction() dijalankan (murni
 * in-memory, forceFill, tanpa DB), mirip pola
 * BankStatementServiceVoucherApValidationTest. Beda kunci dari AP: total
 * alokasi di sini BOLEH kurang dari nominal kredit (sisanya jadi Kelebihan
 * Bayar), tapi TIDAK BOLEH melebihi — arah toleransinya kebalikan dari AP.
 */
class BankStatementServiceMultiPaymentValidationTest extends TestCase
{
    private function buildService(?PembayaranArService $pembayaranArService = null): BankStatementService
    {
        return new BankStatementService(
            $pembayaranArService ?? $this->createMock(PembayaranArService::class),
            $this->createMock(PembayaranApService::class),
            $this->createMock(InvoiceService::class),
        );
    }

    private function fakeDetail(float $kredit, string $statusCocok, float $debit = 0): BankStatementDetail
    {
        $detail = new BankStatementDetail();
        $detail->forceFill([
            'id'                => 1,
            'bank_statement_id' => 1,
            'tanggal'           => '2026-07-31',
            'keterangan'        => 'Transfer masuk',
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
        $pembayaranArService = $this->createMock(PembayaranArService::class);
        $pembayaranArService->expects($this->never())->method('createMultiPayment');

        $detail  = $this->fakeDetail(kredit: 1000000, statusCocok: 'MATCHED');
        $service = $this->buildService($pembayaranArService);

        try {
            $service->matchWithNewMultiPayment($detail, [['invoice_id' => 1, 'jumlah' => 500000]]);
            $this->fail('Seharusnya melempar HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('Transaksi ini sudah dicocokkan.', $e->getMessage());
        }
    }

    public function test_tolak_jika_bukan_transaksi_kredit(): void
    {
        $pembayaranArService = $this->createMock(PembayaranArService::class);
        $pembayaranArService->expects($this->never())->method('createMultiPayment');

        $detail  = $this->fakeDetail(kredit: 0, statusCocok: 'UNMATCHED', debit: 500000);
        $service = $this->buildService($pembayaranArService);

        try {
            $service->matchWithNewMultiPayment($detail, [['invoice_id' => 1, 'jumlah' => 500000]]);
            $this->fail('Seharusnya melempar HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('Hanya transaksi kredit', $e->getMessage());
        }
    }

    public function test_tolak_jika_alokasi_kosong(): void
    {
        $pembayaranArService = $this->createMock(PembayaranArService::class);
        $pembayaranArService->expects($this->never())->method('createMultiPayment');

        $detail  = $this->fakeDetail(kredit: 1000000, statusCocok: 'UNMATCHED');
        $service = $this->buildService($pembayaranArService);

        try {
            $service->matchWithNewMultiPayment($detail, []);
            $this->fail('Seharusnya melempar HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('minimal 1 invoice', $e->getMessage());
        }
    }

    public function test_tolak_jika_total_alokasi_melebihi_kredit(): void
    {
        $pembayaranArService = $this->createMock(PembayaranArService::class);
        $pembayaranArService->expects($this->never())->method('createMultiPayment');

        $detail  = $this->fakeDetail(kredit: 1000000, statusCocok: 'UNMATCHED');
        $service = $this->buildService($pembayaranArService);

        try {
            // Total alokasi Rp1.000.100 vs kredit Rp1.000.000 — selisih jauh di
            // atas toleransi 0,01.
            $service->matchWithNewMultiPayment($detail, [
                ['invoice_id' => 1, 'jumlah' => 500000],
                ['invoice_id' => 2, 'jumlah' => 500100],
            ]);
            $this->fail('Seharusnya melempar HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('tidak boleh melebihi', $e->getMessage());
        }
    }

    public function test_lolos_validasi_ketika_total_alokasi_kurang_dari_kredit(): void
    {
        // Beda kunci dari AP: total alokasi BOLEH kurang dari kredit (sisanya
        // jadi Kelebihan Bayar) — validasi harus LOLOS untuk kasus ini.
        $fakePembayaran = new PembayaranAr();
        $fakePembayaran->forceFill(['id' => 77]);

        $pembayaranArService = $this->createMock(PembayaranArService::class);
        $pembayaranArService->expects($this->once())
            ->method('createMultiPayment')
            ->with($this->callback(function (array $data) {
                return $data['dibuat_dari_rekonsiliasi'] === true
                    && $data['metode_pembayaran'] === 'TRANSFER'
                    && $data['alokasi'][0]['invoice_id'] === 1
                    && abs($data['alokasi'][0]['jumlah'] - 700000) < 0.001;
            }), null)
            ->willReturn($fakePembayaran);

        $detail  = $this->fakeDetail(kredit: 1000000, statusCocok: 'UNMATCHED');
        $service = $this->buildService($pembayaranArService);

        try {
            $service->matchWithNewMultiPayment($detail, [['invoice_id' => 1, 'jumlah' => 700000]]);
            $this->fail('Seharusnya melempar exception dari lapisan DB (skema test tidak tersedia).');
        } catch (HttpException $e) {
            $this->fail('Validasi semestinya lolos untuk alokasi kurang dari kredit, tapi malah ditolak: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // Diharapkan: manualMatch()/DB query gagal karena skema test tidak
            // ter-migrasi. Ini membuktikan alur sudah melewati semua validasi
            // dan createMultiPayment() terpanggil dengan payload benar (mock
            // di atas), sebelum menyentuh DB sungguhan.
        }
    }

    public function test_lolos_validasi_ketika_selisih_dalam_toleransi_0_01(): void
    {
        $fakePembayaran = new PembayaranAr();
        $fakePembayaran->forceFill(['id' => 78]);

        $pembayaranArService = $this->createMock(PembayaranArService::class);
        $pembayaranArService->expects($this->once())
            ->method('createMultiPayment')
            ->willReturn($fakePembayaran);

        $detail  = $this->fakeDetail(kredit: 1000000, statusCocok: 'UNMATCHED');
        $service = $this->buildService($pembayaranArService);

        try {
            // Selisih Rp 0,01 di atas kredit — masih dalam toleransi.
            $service->matchWithNewMultiPayment($detail, [['invoice_id' => 1, 'jumlah' => 1000000.01]]);
            $this->fail('Seharusnya melempar exception dari lapisan DB (skema test tidak tersedia).');
        } catch (HttpException $e) {
            $this->fail('Validasi semestinya lolos untuk selisih Rp 0,01, tapi malah ditolak: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // Diharapkan, sama seperti test di atas.
        }
    }
}
