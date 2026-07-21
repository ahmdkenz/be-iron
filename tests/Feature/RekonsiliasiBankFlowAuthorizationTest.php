<?php

namespace Tests\Feature;

use App\Domain\Finance\RekonsiliasiBankStatement\Controllers\BankStatementController;
use App\Domain\Finance\RekonsiliasiBankStatement\Services\BankStatementService;
use App\Models\BankStatementDetail;
use App\Models\User;
use App\Support\Enums\RoleEnum;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * PIC AR murni & PIC AP murni berbagi 1 grup middleware route
 * (role:ADMIN|MANAGER|SUPERVISOR|AR|AP) di rekonsiliasi-bank, jadi pembeda
 * "AR tidak boleh sentuh alur AP dan sebaliknya" harus ditegakkan di
 * controller (authorizeArFlow/authorizeApFlow/authorizeRowScope), bukan di
 * middleware. Test ini memanggil private method lewat reflection + auth()
 * yang di-set via actingAs() — tidak menyentuh DB sama sekali (konsisten
 * dengan konvensi project, lihat MasterImportServiceTest), karena rute
 * BankStatementDetail pakai implicit route-model-binding yang butuh DB kalau
 * diuji lewat HTTP penuh.
 */
class RekonsiliasiBankFlowAuthorizationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeUser(array $roles): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasAnyRole')->andReturnUsing(
            fn($needles) => count(array_intersect(array_map(
                fn($r) => $r instanceof RoleEnum ? $r->value : $r,
                (array) $needles
            ), $roles)) > 0
        );
        $user->forceFill(['id' => 1, 'username' => 'tester']);

        return $user;
    }

    private function controller(): BankStatementController
    {
        return new BankStatementController($this->createMock(BankStatementService::class));
    }

    private function invokePrivate(BankStatementController $controller, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionClass($controller);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($controller, ...$args);
    }

    private function fakeDetail(float $debit, float $kredit): BankStatementDetail
    {
        $detail = new BankStatementDetail();
        $detail->forceFill(['id' => 1, 'debit' => $debit, 'kredit' => $kredit]);

        return $detail;
    }

    // ── authorizeArFlow() ─────────────────────────────────────────────

    public function test_ap_murni_ditolak_akses_alur_ar(): void
    {
        $this->actingAs($this->makeUser(['AP']));

        try {
            $this->invokePrivate($this->controller(), 'authorizeArFlow');
            $this->fail('Seharusnya melempar HttpException 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_ar_murni_lolos_akses_alur_ar(): void
    {
        $this->actingAs($this->makeUser(['AR']));

        $this->invokePrivate($this->controller(), 'authorizeArFlow');
        $this->addToAssertionCount(1); // tidak melempar exception = lolos
    }

    public function test_admin_lolos_akses_alur_ar(): void
    {
        $this->actingAs($this->makeUser(['ADMIN']));

        $this->invokePrivate($this->controller(), 'authorizeArFlow');
        $this->addToAssertionCount(1);
    }

    // ── authorizeApFlow() ─────────────────────────────────────────────

    public function test_ar_murni_ditolak_akses_alur_ap(): void
    {
        $this->actingAs($this->makeUser(['AR']));

        try {
            $this->invokePrivate($this->controller(), 'authorizeApFlow');
            $this->fail('Seharusnya melempar HttpException 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_ap_murni_lolos_akses_alur_ap(): void
    {
        $this->actingAs($this->makeUser(['AP']));

        $this->invokePrivate($this->controller(), 'authorizeApFlow');
        $this->addToAssertionCount(1);
    }

    public function test_manager_lolos_akses_alur_ap(): void
    {
        $this->actingAs($this->makeUser(['MANAGER']));

        $this->invokePrivate($this->controller(), 'authorizeApFlow');
        $this->addToAssertionCount(1);
    }

    // ── authorizeRowScope() ───────────────────────────────────────────

    public function test_ap_murni_ditolak_abaikan_unmatch_baris_kredit(): void
    {
        $this->actingAs($this->makeUser(['AP']));
        $detail = $this->fakeDetail(debit: 0, kredit: 500000);

        try {
            $this->invokePrivate($this->controller(), 'authorizeRowScope', $detail);
            $this->fail('Seharusnya melempar HttpException 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_ap_murni_lolos_abaikan_unmatch_baris_debit(): void
    {
        $this->actingAs($this->makeUser(['AP']));
        $detail = $this->fakeDetail(debit: 500000, kredit: 0);

        $this->invokePrivate($this->controller(), 'authorizeRowScope', $detail);
        $this->addToAssertionCount(1);
    }

    public function test_ar_murni_ditolak_abaikan_unmatch_baris_debit(): void
    {
        $this->actingAs($this->makeUser(['AR']));
        $detail = $this->fakeDetail(debit: 500000, kredit: 0);

        try {
            $this->invokePrivate($this->controller(), 'authorizeRowScope', $detail);
            $this->fail('Seharusnya melempar HttpException 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_ar_murni_lolos_abaikan_unmatch_baris_kredit(): void
    {
        $this->actingAs($this->makeUser(['AR']));
        $detail = $this->fakeDetail(debit: 0, kredit: 500000);

        $this->invokePrivate($this->controller(), 'authorizeRowScope', $detail);
        $this->addToAssertionCount(1);
    }

    public function test_supervisor_lolos_abaikan_unmatch_baris_manapun(): void
    {
        $this->actingAs($this->makeUser(['SUPERVISOR']));

        $this->invokePrivate($this->controller(), 'authorizeRowScope', $this->fakeDetail(debit: 500000, kredit: 0));
        $this->invokePrivate($this->controller(), 'authorizeRowScope', $this->fakeDetail(debit: 0, kredit: 500000));
        $this->addToAssertionCount(2);
    }
}
