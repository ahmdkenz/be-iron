<?php

namespace Tests\Feature;

use App\Domain\Finance\TagihanAp\Services\TagihanApService;
use App\Models\Karyawan;
use App\Models\User;
use App\Support\Enums\RoleEnum;
use App\Support\Helpers\ApFilterScope;
use Illuminate\Database\Eloquent\Collection;
use Mockery;
use Tests\TestCase;

/**
 * Menguji bahwa TagihanApController & OpeningBalanceApController meneruskan
 * filter (search/status/approval_status/vendor_ap_id/tanggal) yang sama ke
 * summary() maupun exportExcel(). Sebelumnya TagihanApController::exportExcel()
 * mengabaikan seluruh filter (selalu export semua data) — ini regression test
 * untuk perbaikan itu.
 *
 * Menghindari RefreshDatabase karena migrate:fresh rusak di project ini
 * (lihat tests/Feature/MasterImportServiceTest.php) — TagihanApService
 * di-mock total, relasi karyawan di-set manual via setRelation() supaya
 * ApFilterScope::apply() (yang beneran jalan, bukan di-mock) tidak memicu
 * query ke DB lewat loadMissing('karyawan').
 */
class TagihanApSummaryExportFilterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeUser(array $roles, ?Karyawan $karyawan = null): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasAnyRole')->andReturnUsing(
            fn($needles) => count(array_intersect(array_map(
                fn($r) => $r instanceof RoleEnum ? $r->value : $r,
                (array) $needles
            ), $roles)) > 0
        );
        $user->forceFill(['id' => 1, 'username' => 'tester']);
        $user->setRelation('karyawan', $karyawan);

        return $user;
    }

    private function bindService(): TagihanApService&\Mockery\MockInterface
    {
        $service = Mockery::mock(TagihanApService::class);
        $this->app->instance(TagihanApService::class, $service);

        return $service;
    }

    private function emptySummaryShape(): array
    {
        return [
            'total_tagihan' => 0, 'total_nominal' => 0.0, 'total_pembayaran' => 0.0, 'total_sisa' => 0.0,
            'payment_rate_pct' => 0.0, 'outstanding_count' => 0,
            'overdue_count' => 0, 'overdue_amount' => 0.0,
            'due_soon_count' => 0, 'due_soon_amount' => 0.0,
            'status_breakdown' => [], 'approval_breakdown' => [], 'top_vendors' => [], 'due_soon' => [],
        ];
    }

    // ── GET /ap/tagihan/summary ───────────────────────────────────────

    public function test_summary_tagihan_ap_meneruskan_filter_request_apa_adanya(): void
    {
        $service = $this->bindService();
        $service->shouldReceive('getSummary')->once()->withArgs(function (array $filters) {
            $this->assertSame('PT Vendor', $filters['search']);
            $this->assertSame('DITERIMA', $filters['status']);
            $this->assertSame('APPROVED', $filters['approval_status']);
            $this->assertSame('5', $filters['vendor_ap_id']);
            $this->assertSame('2026-07-01', $filters['tanggal_dari']);
            $this->assertSame('2026-07-31', $filters['tanggal_sampai']);
            $this->assertFalse($filters['is_opening_balance']);
            $this->assertArrayNotHasKey('karyawan_id', $filters);
            $this->assertArrayNotHasKey('perusahaan_id', $filters);
            $this->assertArrayNotHasKey('pic_ap_karyawan_id', $filters);

            return true;
        })->andReturn($this->emptySummaryShape());

        $response = $this->actingAs($this->makeUser(['ADMIN']))
            ->getJson('/api/v1/ap/tagihan/summary?' . http_build_query([
                'search'          => 'PT Vendor',
                'status'          => 'DITERIMA',
                'approval_status' => 'APPROVED',
                'vendor_ap_id'    => 5,
                'tanggal_dari'    => '2026-07-01',
                'tanggal_sampai'  => '2026-07-31',
            ]));

        $response->assertOk();
    }

    public function test_summary_tagihan_ap_untuk_ap_staff_discope_ke_pic_ap_karyawan_id(): void
    {
        $karyawan = new Karyawan();
        $karyawan->forceFill(['id' => 42, 'perusahaan_id' => 7]);

        $service = $this->bindService();
        $service->shouldReceive('getSummary')->once()->withArgs(function (array $filters) {
            $this->assertSame(42, $filters['pic_ap_karyawan_id']);
            $this->assertArrayNotHasKey('perusahaan_id', $filters);

            return true;
        })->andReturn($this->emptySummaryShape());

        $response = $this->actingAs($this->makeUser(['AP'], $karyawan))
            ->getJson('/api/v1/ap/tagihan/summary');

        $response->assertOk();
    }

    // ── GET /ap/tagihan/export-excel (regresi: dulu abai filter) ──────

    public function test_export_excel_tagihan_ap_meneruskan_filter_yang_sama_dengan_summary(): void
    {
        $service = $this->bindService();
        $service->shouldReceive('getAll')->once()->withArgs(function (array $filters, ?array $with) {
            $this->assertSame('PT Vendor', $filters['search']);
            $this->assertSame('DITERIMA', $filters['status']);
            $this->assertFalse($filters['is_opening_balance']);

            return true;
        })->andReturn(new Collection());

        $response = $this->actingAs($this->makeUser(['ADMIN']))
            ->get('/api/v1/ap/tagihan/export-excel?' . http_build_query([
                'search' => 'PT Vendor',
                'status' => 'DITERIMA',
            ]));

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
    }

    // ── GET /ap/opening-balance/summary ────────────────────────────────

    public function test_summary_opening_balance_ap_meneruskan_filter_request_apa_adanya(): void
    {
        $service = $this->bindService();
        $service->shouldReceive('getSummary')->once()->withArgs(function (array $filters) {
            $this->assertSame('PT Vendor', $filters['search']);
            $this->assertSame('PENDING', $filters['approval_status']);
            $this->assertTrue($filters['is_opening_balance']);

            return true;
        })->andReturn($this->emptySummaryShape());

        $response = $this->actingAs($this->makeUser(['MANAGER']))
            ->getJson('/api/v1/ap/opening-balance/summary?' . http_build_query([
                'search'          => 'PT Vendor',
                'approval_status' => 'PENDING',
            ]));

        $response->assertOk();
    }

    // ── ApFilterScope::apply (murni, tanpa DB) ─────────────────────────

    public function test_ap_filter_scope_tidak_menambah_scope_untuk_user_global_access(): void
    {
        $filters = ['search' => 'x'];
        ApFilterScope::apply($filters, $this->makeUser(['ADMIN']));

        $this->assertSame(['search' => 'x'], $filters);
    }

    public function test_ap_filter_scope_hapus_perusahaan_dan_karyawan_id_untuk_pic_ap(): void
    {
        $karyawan = new Karyawan();
        $karyawan->forceFill(['id' => 10, 'perusahaan_id' => 3]);

        $filters = ['karyawan_id' => 999];
        ApFilterScope::apply($filters, $this->makeUser(['AP'], $karyawan));

        $this->assertArrayNotHasKey('karyawan_id', $filters);
        $this->assertSame(10, $filters['pic_ap_karyawan_id']);
    }
}
