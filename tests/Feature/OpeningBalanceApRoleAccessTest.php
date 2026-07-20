<?php

namespace Tests\Feature;

use App\Domain\Finance\TagihanAp\Services\TagihanApService;
use App\Models\TagihanAp;
use App\Models\User;
use App\Support\Enums\RoleEnum;
use App\Support\Helpers\RoleHelper;
use Mockery;
use Tests\TestCase;

/**
 * Manager & Supervisor kini disamakan dengan akses operasi AP untuk Opening
 * Balance AP (sebelumnya hanya ADMIN & AP). Test ini menghindari
 * RefreshDatabase karena migrate:fresh rusak di project ini (lihat
 * tests/Feature/MasterImportServiceTest.php) — TagihanApService di-mock
 * total sehingga endpoint yang diuji tidak pernah menyentuh DB. Store/update
 * tidak diuji lewat HTTP di sini karena StoreOpeningBalanceApRequest divalidasi
 * (rule exists/unique ke tb_vendor_ap & tb_tagihan_ap) sebelum authorizeOperate()
 * sempat jalan, sehingga butuh DB nyata untuk payload yang valid.
 */
class OpeningBalanceApRoleAccessTest extends TestCase
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

    private function fakeOpeningBalance(): TagihanAp
    {
        $tagihan = new TagihanAp();
        $tagihan->forceFill([
            'id'                 => 99,
            'no_tagihan'         => 'OB-2026-07-0001',
            'is_opening_balance' => true,
            'status'             => 'DRAFT',
            'approval_status'    => 'REJECTED',
            'subtotal'           => 1000000,
            'total_tagihan'      => 1000000,
            'total_pembayaran'   => 0,
            'total_penyesuaian'  => 0,
            'sisa_tagihan'       => 1000000,
        ]);

        return $tagihan;
    }

    private function bindService(): TagihanApService&\Mockery\MockInterface
    {
        $service = Mockery::mock(TagihanApService::class);
        $this->app->instance(TagihanApService::class, $service);

        return $service;
    }

    // ── RoleHelper: sumber kebenaran akses operasi ──────────────────

    public function test_role_helper_operate_mengizinkan_admin_manager_supervisor_ap(): void
    {
        foreach (['ADMIN', 'MANAGER', 'SUPERVISOR', 'AP'] as $role) {
            $this->assertTrue(
                RoleHelper::canOperateOpeningBalanceAp($this->makeUser([$role])),
                "Role {$role} seharusnya bisa mengelola opening balance AP"
            );
        }
    }

    public function test_role_helper_operate_menolak_role_ar(): void
    {
        $this->assertFalse(RoleHelper::canOperateOpeningBalanceAp($this->makeUser(['AR'])));
    }

    // ── GET /ap/opening-balance/preview-no ───────────────────────────

    public function test_manager_bisa_preview_no_opening_balance(): void
    {
        $service = $this->bindService();
        $service->shouldReceive('generateOpeningBalanceNoTagihan')->once()->andReturn('OB-2026-07-0002');

        $response = $this->actingAs($this->makeUser(['MANAGER']))
            ->getJson('/api/v1/ap/opening-balance/preview-no?tanggal=2026-07-20');

        $response->assertOk()->assertJsonPath('data.no_tagihan', 'OB-2026-07-0002');
    }

    public function test_supervisor_bisa_preview_no_opening_balance(): void
    {
        $service = $this->bindService();
        $service->shouldReceive('generateOpeningBalanceNoTagihan')->once()->andReturn('OB-2026-07-0003');

        $response = $this->actingAs($this->makeUser(['SUPERVISOR']))
            ->getJson('/api/v1/ap/opening-balance/preview-no?tanggal=2026-07-20');

        $response->assertOk()->assertJsonPath('data.no_tagihan', 'OB-2026-07-0003');
    }

    public function test_role_ar_tidak_bisa_preview_no_opening_balance(): void
    {
        $service = $this->bindService();
        $service->shouldNotReceive('generateOpeningBalanceNoTagihan');

        $response = $this->actingAs($this->makeUser(['AR']))
            ->getJson('/api/v1/ap/opening-balance/preview-no?tanggal=2026-07-20');

        $response->assertForbidden();
    }

    // ── PATCH /ap/opening-balance/{id}/resubmit ──────────────────────

    public function test_manager_bisa_resubmit_opening_balance_yang_ditolak(): void
    {
        $tagihan = $this->fakeOpeningBalance();
        $service = $this->bindService();
        $service->shouldReceive('findOrFail')->once()->with(99)->andReturn($tagihan);
        $service->shouldReceive('resubmitOpeningBalance')->once()->andReturn($tagihan);

        $response = $this->actingAs($this->makeUser(['MANAGER']))
            ->patchJson('/api/v1/ap/opening-balance/99/resubmit', []);

        $response->assertOk();
    }

    public function test_supervisor_bisa_resubmit_opening_balance_yang_ditolak(): void
    {
        $tagihan = $this->fakeOpeningBalance();
        $service = $this->bindService();
        $service->shouldReceive('findOrFail')->once()->with(99)->andReturn($tagihan);
        $service->shouldReceive('resubmitOpeningBalance')->once()->andReturn($tagihan);

        $response = $this->actingAs($this->makeUser(['SUPERVISOR']))
            ->patchJson('/api/v1/ap/opening-balance/99/resubmit', []);

        $response->assertOk();
    }

    public function test_role_ar_tidak_bisa_resubmit_opening_balance(): void
    {
        $service = $this->bindService();
        $service->shouldNotReceive('findOrFail');
        $service->shouldNotReceive('resubmitOpeningBalance');

        $response = $this->actingAs($this->makeUser(['AR']))
            ->patchJson('/api/v1/ap/opening-balance/99/resubmit', []);

        $response->assertForbidden();
    }

    // ── GET /ap/opening-balance/{id} (endpoint baru) ─────────────────

    public function test_manager_bisa_melihat_detail_opening_balance(): void
    {
        $tagihan = $this->fakeOpeningBalance();
        $service = $this->bindService();
        $service->shouldReceive('findOrFail')->once()->with(99)->andReturn($tagihan);

        $response = $this->actingAs($this->makeUser(['MANAGER']))
            ->getJson('/api/v1/ap/opening-balance/99');

        $response->assertOk()
            ->assertJsonPath('data.id', 99)
            ->assertJsonPath('data.no_tagihan', 'OB-2026-07-0001')
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.can_resubmit', true);
    }

    public function test_role_ar_tidak_bisa_melihat_detail_opening_balance(): void
    {
        $service = $this->bindService();
        $service->shouldNotReceive('findOrFail');

        $response = $this->actingAs($this->makeUser(['AR']))
            ->getJson('/api/v1/ap/opening-balance/99');

        $response->assertForbidden();
    }

    public function test_get_opening_balance_yang_bukan_opening_balance_dianggap_404(): void
    {
        $tagihanBiasa = new TagihanAp();
        $tagihanBiasa->forceFill(['id' => 5, 'is_opening_balance' => false]);

        $service = $this->bindService();
        $service->shouldReceive('findOrFail')->once()->with(5)->andReturn($tagihanBiasa);

        $response = $this->actingAs($this->makeUser(['MANAGER']))
            ->getJson('/api/v1/ap/opening-balance/5');

        $response->assertNotFound();
    }
}
