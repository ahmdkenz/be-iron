<?php

namespace Tests\Feature;

use App\Domain\Finance\OpeningBalance\Jobs\ProcessOpeningBalanceBulkApproveJob;
use App\Domain\Finance\OpeningBalance\Services\OpeningBalanceBulkApproveCacheService;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * "Approve Semua" Opening Balance dulu sinkron dalam 1 request (gampang
 * timeout untuk batch besar — lihat cascadeCarryoverToNext di InvoiceService).
 * Sekarang endpoint hanya dispatch job & simpan progres di Cache (bukan
 * tabel DB baru, lihat OpeningBalanceBulkApproveCacheService).
 *
 * Test ini menghindari RefreshDatabase (migrate:fresh rusak di project ini,
 * lihat memory project_test_db_migrate_fresh_broken / OpeningBalanceApRoleAccessTest)
 * — User di-mock total & job di-fake (Queue::fake()) sehingga tidak pernah
 * menyentuh DB nyata. Logic loop approve/fail per-item di dalam
 * ProcessOpeningBalanceBulkApproveJob::handle() sengaja TIDAK dieksekusi di
 * sini (butuh User::find() + InvoiceService yang menyentuh DB) — hanya
 * kontrak dispatch-nya (ids/note/user diterima job dengan benar) yang diuji.
 */
class OpeningBalanceBulkApproveTest extends TestCase
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
            fn ($needles) => count(array_intersect(array_map(
                fn ($r) => is_string($r) ? $r : $r->value,
                (array) $needles
            ), $roles)) > 0
        );
        $user->forceFill(['id' => $id, 'username' => 'tester'.$id]);

        return $user;
    }

    private function cache(): OpeningBalanceBulkApproveCacheService
    {
        return app(OpeningBalanceBulkApproveCacheService::class);
    }

    // ── PATCH /finance/opening-balance/bulk-approve ──────────────────

    public function test_manager_bisa_memicu_approve_semua_dan_job_di_dispatch(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->makeUser(['MANAGER']))
            ->patchJson('/api/v1/finance/opening-balance/bulk-approve', ['ids' => [1, 2, 3]]);

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.total', 3);

        $batchId = $response->json('data.batch_id');
        $this->assertNotEmpty($batchId);

        Queue::assertPushed(ProcessOpeningBalanceBulkApproveJob::class, function ($job) use ($batchId) {
            $ref = new \ReflectionClass($job);

            $getProp = fn (string $name) => $ref->getProperty($name)->getValue($job);

            return $getProp('batchId') === $batchId
                && $getProp('ids') === [1, 2, 3]
                && $getProp('userId') === 1;
        });
    }

    public function test_role_ar_tidak_bisa_memicu_approve_semua(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->makeUser(['AR']))
            ->patchJson('/api/v1/finance/opening-balance/bulk-approve', ['ids' => [1, 2]]);

        $response->assertForbidden();
        Queue::assertNotPushed(ProcessOpeningBalanceBulkApproveJob::class);
    }

    public function test_approve_semua_ditolak_409_jika_masih_ada_batch_aktif_untuk_user_yang_sama(): void
    {
        Queue::fake();
        $this->cache()->startBatch(1, 5);

        $response = $this->actingAs($this->makeUser(['MANAGER'], id: 1))
            ->patchJson('/api/v1/finance/opening-balance/bulk-approve', ['ids' => [10, 11]]);

        $response->assertStatus(409);
        Queue::assertNotPushed(ProcessOpeningBalanceBulkApproveJob::class);
    }

    public function test_approve_semua_tidak_terblokir_untuk_user_lain_meski_ada_batch_aktif(): void
    {
        Queue::fake();
        $this->cache()->startBatch(1, 5);

        $response = $this->actingAs($this->makeUser(['SUPERVISOR'], id: 2))
            ->patchJson('/api/v1/finance/opening-balance/bulk-approve', ['ids' => [10, 11]]);

        $response->assertStatus(202);
        Queue::assertPushed(ProcessOpeningBalanceBulkApproveJob::class);
    }

    // ── GET /finance/opening-balance/bulk-approve/active ─────────────

    public function test_bulk_approve_active_null_jika_tidak_ada_batch_berjalan(): void
    {
        $response = $this->actingAs($this->makeUser(['MANAGER'], id: 1))
            ->getJson('/api/v1/finance/opening-balance/bulk-approve/active');

        $response->assertOk()->assertJsonPath('data', null);
    }

    public function test_bulk_approve_active_mengembalikan_progress_batch_milik_user(): void
    {
        $this->cache()->startBatch(1, 7);

        $response = $this->actingAs($this->makeUser(['MANAGER'], id: 1))
            ->getJson('/api/v1/finance/opening-balance/bulk-approve/active');

        $response->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.total', 7);
    }

    // ── GET /finance/opening-balance/bulk-approve/{batch}/status ─────

    public function test_bulk_approve_status_mengembalikan_progress_dari_cache(): void
    {
        $batchId = $this->cache()->startBatch(1, 4);
        $this->cache()->update($batchId, ['status' => 'processing', 'processed' => 2, 'approved' => 2]);

        $response = $this->actingAs($this->makeUser(['MANAGER'], id: 1))
            ->getJson("/api/v1/finance/opening-balance/bulk-approve/{$batchId}/status");

        $response->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.processed', 2)
            ->assertJsonPath('data.approved', 2);
    }

    public function test_bulk_approve_status_404_jika_batch_tidak_ada_atau_kedaluwarsa(): void
    {
        $response = $this->actingAs($this->makeUser(['MANAGER'], id: 1))
            ->getJson('/api/v1/finance/opening-balance/bulk-approve/batch-tidak-ada/status');

        $response->assertNotFound();
    }
}
