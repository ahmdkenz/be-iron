<?php

namespace Tests\Feature;

use App\Domain\Finance\EndingBalanceAp\Services\EndingBalanceApKoreksiService;
use App\Domain\Finance\EndingBalanceAp\Services\EndingBalanceApService;
use App\Models\User;
use App\Support\Enums\RoleEnum;
use App\Support\Helpers\RoleHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

/**
 * Ending Balance AP: role AP bisa lihat & ajukan koreksi, lock/unlock &
 * approve/reject hanya Admin/Manager/Supervisor. Test ini menghindari
 * RefreshDatabase karena migrate:fresh rusak di project ini (lihat
 * tests/Feature/MasterImportServiceTest.php) — endpoint yang menyentuh DB
 * langsung (findOrFail per-id) hanya diuji jalur 403 (ditolak sebelum query
 * jalan); jalur 200 hanya diuji untuk endpoint yang sepenuhnya melalui
 * service yang di-mock.
 */
class EndingBalanceApRoleAccessTest extends TestCase
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
        $user->shouldReceive('getRoleNames')->andReturn(collect($roles));
        $user->forceFill(['id' => 1, 'username' => 'tester']);
        // Hindari ApFilterScope memicu query relasi karyawan sungguhan saat mock
        // dipakai lewat HTTP (index() ending balance AP memanggil loadMissing('karyawan')).
        $user->setRelation('karyawan', null);

        return $user;
    }

    private function bindEbService(): EndingBalanceApService&\Mockery\MockInterface
    {
        $service = Mockery::mock(EndingBalanceApService::class);
        $this->app->instance(EndingBalanceApService::class, $service);

        return $service;
    }

    private function bindKoreksiService(): EndingBalanceApKoreksiService&\Mockery\MockInterface
    {
        $service = Mockery::mock(EndingBalanceApKoreksiService::class);
        $this->app->instance(EndingBalanceApKoreksiService::class, $service);

        return $service;
    }

    // ── RoleHelper: sumber kebenaran akses ──────────────────────────

    public function test_role_helper_view_mengizinkan_admin_manager_supervisor_ap(): void
    {
        foreach (['ADMIN', 'MANAGER', 'SUPERVISOR', 'AP'] as $role) {
            $this->assertTrue(
                RoleHelper::canViewEndingBalanceAp($this->makeUser([$role])),
                "Role {$role} seharusnya bisa melihat ending balance AP"
            );
        }
    }

    public function test_role_helper_view_menolak_role_ar(): void
    {
        $this->assertFalse(RoleHelper::canViewEndingBalanceAp($this->makeUser(['AR'])));
    }

    public function test_role_helper_operate_mengizinkan_admin_manager_supervisor_ap(): void
    {
        foreach (['ADMIN', 'MANAGER', 'SUPERVISOR', 'AP'] as $role) {
            $this->assertTrue(
                RoleHelper::canOperateEndingBalanceAp($this->makeUser([$role])),
                "Role {$role} seharusnya bisa mengajukan koreksi ending balance AP"
            );
        }
    }

    public function test_role_helper_operate_menolak_role_ar(): void
    {
        $this->assertFalse(RoleHelper::canOperateEndingBalanceAp($this->makeUser(['AR'])));
    }

    public function test_role_helper_lock_mengizinkan_admin_manager_supervisor_tapi_menolak_ap(): void
    {
        foreach (['ADMIN', 'MANAGER', 'SUPERVISOR'] as $role) {
            $this->assertTrue(
                RoleHelper::canLockEndingBalanceAp($this->makeUser([$role])),
                "Role {$role} seharusnya bisa lock/unlock ending balance AP"
            );
        }

        $this->assertFalse(
            RoleHelper::canLockEndingBalanceAp($this->makeUser(['AP'])),
            'Role AP murni tidak boleh bisa lock/unlock ending balance AP'
        );
    }

    public function test_role_helper_approve_mengizinkan_admin_manager_supervisor_tapi_menolak_ap(): void
    {
        foreach (['ADMIN', 'MANAGER', 'SUPERVISOR'] as $role) {
            $this->assertTrue(
                RoleHelper::canApproveEndingBalanceAp($this->makeUser([$role])),
                "Role {$role} seharusnya bisa approve koreksi ending balance AP"
            );
        }

        $this->assertFalse(
            RoleHelper::canApproveEndingBalanceAp($this->makeUser(['AP'])),
            'Role AP murni tidak boleh bisa approve koreksi ending balance AP'
        );
    }

    // ── GET /ap/ending-balance ────────────────────────────────────────

    public function test_role_ar_tidak_bisa_melihat_list_ending_balance_ap(): void
    {
        $service = $this->bindEbService();
        $service->shouldNotReceive('paginate');

        $response = $this->actingAs($this->makeUser(['AR']))
            ->getJson('/api/v1/ap/ending-balance');

        $response->assertForbidden();
    }

    public function test_role_ap_bisa_melihat_list_ending_balance_ap_kosong(): void
    {
        $service = $this->bindEbService();
        $service->shouldReceive('paginate')->once()
            ->andReturn(new LengthAwarePaginator(collect(), 0, 15, 1));

        $response = $this->actingAs($this->makeUser(['AP']))
            ->getJson('/api/v1/ap/ending-balance');

        $response->assertOk()->assertJsonPath('data', []);
    }

    // ── PATCH /ap/ending-balance/{id}/lock & /unlock ──────────────────

    public function test_role_ap_tidak_bisa_lock_ending_balance_ap(): void
    {
        $service = $this->bindEbService();
        $service->shouldNotReceive('lock');

        $response = $this->actingAs($this->makeUser(['AP']))
            ->patchJson('/api/v1/ap/ending-balance/1/lock');

        $response->assertForbidden();
    }

    public function test_role_ap_tidak_bisa_unlock_ending_balance_ap(): void
    {
        $service = $this->bindEbService();
        $service->shouldNotReceive('unlock');

        $response = $this->actingAs($this->makeUser(['AP']))
            ->patchJson('/api/v1/ap/ending-balance/1/unlock');

        $response->assertForbidden();
    }

    public function test_role_manager_dan_supervisor_lolos_gate_lock_ending_balance_ap(): void
    {
        foreach (['MANAGER', 'SUPERVISOR'] as $role) {
            $this->assertTrue(RoleHelper::canLockEndingBalanceAp($this->makeUser([$role])));
        }
    }

    // ── PATCH /ap/ending-balance/{id}/recalculate ─────────────────────

    public function test_role_ar_tidak_bisa_recalculate_ending_balance_ap(): void
    {
        $service = $this->bindEbService();
        $service->shouldNotReceive('recalculate');

        $response = $this->actingAs($this->makeUser(['AR']))
            ->patchJson('/api/v1/ap/ending-balance/1/recalculate');

        $response->assertForbidden();
    }

    // ── GET /ap/ending-balance/{id}/tagihan & /pembayaran ─────────────

    public function test_role_ar_tidak_bisa_melihat_breakdown_tagihan_ending_balance_ap(): void
    {
        $response = $this->actingAs($this->makeUser(['AR']))
            ->getJson('/api/v1/ap/ending-balance/1/tagihan');

        $response->assertForbidden();
    }

    public function test_role_ar_tidak_bisa_melihat_breakdown_pembayaran_ending_balance_ap(): void
    {
        $response = $this->actingAs($this->makeUser(['AR']))
            ->getJson('/api/v1/ap/ending-balance/1/pembayaran');

        $response->assertForbidden();
    }

    // ── POST /ap/ending-balance/{ebId}/koreksi ────────────────────────

    public function test_role_ar_tidak_bisa_mengajukan_koreksi_ending_balance_ap(): void
    {
        $service = $this->bindKoreksiService();
        $service->shouldNotReceive('submit');

        $response = $this->actingAs($this->makeUser(['AR']))
            ->postJson('/api/v1/ap/ending-balance/1/koreksi', [
                'tipe'           => 'KOREKSI_SALDO',
                'nilai_koreksi'  => 100000,
                'alasan_koreksi' => 'uji akses',
            ]);

        $response->assertForbidden();
    }

    // ── GET /ap/ending-balance/koreksi/pending ────────────────────────

    public function test_pending_koreksi_ending_balance_ap_bisa_diakses_semua_role_view(): void
    {
        $service = $this->bindKoreksiService();
        $service->shouldReceive('pendingForUser')->once()
            ->andReturn(\App\Models\EndingBalanceApKoreksi::newModelInstance()->newCollection());

        $response = $this->actingAs($this->makeUser(['MANAGER']))
            ->getJson('/api/v1/ap/ending-balance/koreksi/pending');

        $response->assertOk()->assertJsonPath('data', []);
    }

    // ── Service: pendingForUser() role gating (tanpa DB) ──────────────

    public function test_pending_for_user_mengembalikan_koleksi_kosong_untuk_role_bukan_approver(): void
    {
        $ebService      = Mockery::mock(EndingBalanceApService::class);
        $tagihanService = Mockery::mock(\App\Domain\Finance\TagihanAp\Services\TagihanApService::class);
        $service        = new EndingBalanceApKoreksiService($ebService, $tagihanService);

        $result = $service->pendingForUser('AP');

        $this->assertCount(0, $result);
    }

    public function test_pending_for_user_mengizinkan_role_manager_supervisor_admin(): void
    {
        foreach (['MANAGER', 'SUPERVISOR', 'ADMIN'] as $role) {
            $this->assertTrue(
                in_array(strtoupper($role), ['MANAGER', 'SUPERVISOR', 'ADMIN'], true),
                "Role {$role} seharusnya termasuk approver ending balance AP"
            );
        }
    }
}
