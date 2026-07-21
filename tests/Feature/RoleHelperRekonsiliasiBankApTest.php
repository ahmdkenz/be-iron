<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Enums\RoleEnum;
use App\Support\Helpers\RoleHelper;
use Mockery;
use Tests\TestCase;

/**
 * RoleHelper::canManageMatchedRecord() dipakai bersama oleh baris MATCHED
 * AR (pembayaran_ar_id) maupun AP (pembayaran_ap_id) di Rekonsiliasi Bank —
 * tidak ada helper terpisah untuk AP karena hasGlobalArAccess() (ADMIN,
 * MANAGER, SUPERVISOR) dan hasGlobalApAccess() persis sama isinya. Test ini
 * jadi pengaman regresi: kalau nanti kedua helper itu sengaja dibedakan,
 * test ini akan gagal dan mengingatkan bahwa canManageMatchedRecord() perlu
 * dipecah jadi versi AR & AP terpisah.
 */
class RoleHelperRekonsiliasiBankApTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeUser(array $roles, int $id = 1): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasAnyRole')->andReturnUsing(
            fn($needles) => count(array_intersect(array_map(
                fn($r) => $r instanceof RoleEnum ? $r->value : $r,
                (array) $needles
            ), $roles)) > 0
        );
        $user->forceFill(['id' => $id, 'username' => 'tester']);

        return $user;
    }

    public function test_admin_manager_supervisor_bisa_kelola_baris_matched_ap_walau_bukan_pemilik(): void
    {
        foreach (['ADMIN', 'MANAGER', 'SUPERVISOR'] as $role) {
            $this->assertTrue(
                RoleHelper::canManageMatchedRecord($this->makeUser([$role], id: 1), matchedByUserId: 999),
                "Role {$role} seharusnya tetap bisa kelola baris MATCHED milik user lain"
            );
        }
    }

    public function test_ap_murni_hanya_bisa_kelola_baris_yang_dia_cocokkan_sendiri(): void
    {
        $pemilik = $this->makeUser(['AP'], id: 7);
        $bukanPemilik = $this->makeUser(['AP'], id: 8);

        $this->assertTrue(RoleHelper::canManageMatchedRecord($pemilik, matchedByUserId: 7));
        $this->assertFalse(RoleHelper::canManageMatchedRecord($bukanPemilik, matchedByUserId: 7));
    }

    public function test_ar_murni_tidak_bisa_kelola_baris_matched_ap_milik_orang_lain(): void
    {
        $user = $this->makeUser(['AR'], id: 3);

        $this->assertFalse(RoleHelper::canManageMatchedRecord($user, matchedByUserId: 999));
    }
}
