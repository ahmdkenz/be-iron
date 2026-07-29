<?php

namespace Tests\Feature;

use App\Domain\Finance\EndingBalanceAp\Services\EndingBalanceApKoreksiService;
use App\Domain\Finance\EndingBalanceAp\Services\EndingBalanceApService;
use App\Domain\Finance\TagihanAp\Services\TagihanApService;
use App\Domain\Notification\Services\FinanceNotificationService;
use Tests\TestCase;

/**
 * Unit test untuk generator no_dokumen Credit Note/Debit Note Ending Balance AP.
 * Menggunakan reflection agar method privat bisa diakses tanpa mengubah
 * visibility — generateNoDokumen() sendiri menyentuh DB (cek duplikat), jadi
 * yang diuji di sini adalah buildNoDokumenCandidate() yang murni (lihat
 * tests/Feature/PembayaranApServiceKodeVoucherTest.php untuk pola yang sama,
 * dan project_test_db_migrate_fresh_broken di memory untuk alasan
 * RefreshDatabase tidak dipakai).
 */
class EndingBalanceApKoreksiNoDokumenTest extends TestCase
{
    private EndingBalanceApKoreksiService $service;
    private \ReflectionClass $ref;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EndingBalanceApKoreksiService(
            $this->createMock(EndingBalanceApService::class),
            $this->createMock(TagihanApService::class),
            $this->createMock(FinanceNotificationService::class),
        );
        $this->ref = new \ReflectionClass($this->service);
    }

    private function buildCandidate(string $tipe): string
    {
        $m = $this->ref->getMethod('buildNoDokumenCandidate');
        $m->setAccessible(true);

        return $m->invoke($this->service, $tipe);
    }

    public function test_format_no_dokumen_credit_note(): void
    {
        $tanggal = now()->format('dmY');

        $noDokumen = $this->buildCandidate('CREDIT_NOTE');

        $this->assertMatchesRegularExpression("/^CN-AP-{$tanggal}-\\d{3}$/", $noDokumen);
    }

    public function test_format_no_dokumen_debit_note(): void
    {
        $tanggal = now()->format('dmY');

        $noDokumen = $this->buildCandidate('DEBIT_NOTE');

        $this->assertMatchesRegularExpression("/^DN-AP-{$tanggal}-\\d{3}$/", $noDokumen);
    }

    public function test_suffix_selalu_3_digit_zero_padded(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $noDokumen = $this->buildCandidate('CREDIT_NOTE');
            $suffix    = substr($noDokumen, -3);

            $this->assertMatchesRegularExpression('/^\\d{3}$/', $suffix);
        }
    }

    public function test_tanggal_pengajuan_menentukan_segmen_tanggal_no_dokumen(): void
    {
        $tanggal = now()->format('dmY');

        $noDokumen = $this->buildCandidate('DEBIT_NOTE');

        $this->assertStringContainsString("-{$tanggal}-", $noDokumen);
    }
}
