<?php

namespace Tests\Feature;

use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Models\Karyawan;
use App\Models\KlienAr;
use App\Models\User;
use App\Support\Enums\RoleEnum;
use App\Support\Helpers\RoleHelper;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * POST /finance/opening-balance/bulk — buat banyak Opening Balance sekaligus
 * dalam satu transaksi (all-or-nothing). Menghindari RefreshDatabase karena
 * migrate:fresh rusak di project ini (lihat project_test_db_migrate_fresh_broken
 * di memory) — User di-mock total, InvoiceService di-mock total. Skenario
 * happy-path menstub createOpeningBalanceBulk() supaya mengembalikan array
 * kosong ([]), sehingga InvoiceResource::collection() tidak perlu
 * menyerialisasi model Invoice sungguhan (yang butuh tb_ending_balance dkk,
 * lihat InvoiceResource::toArray()) — cukup untuk membuktikan kontrak
 * (role diizinkan, service dipanggil dengan item yang benar).
 *
 * Skenario yang butuh baris tb_klien_ar sungguhan (validasi exists/distinct,
 * serta authorizeKlienArOwnership per baris) membuat tabelnya sendiri ad-hoc
 * via Schema::create lalu drop lagi, persis pola test_pic_ar_lain_ditolak_403
 * di InvoiceBulkB2CInvestorTest.php.
 */
class OpeningBalanceBulkStoreTest extends TestCase
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
            fn ($needles) => count(array_intersect(array_map(
                fn ($r) => $r instanceof RoleEnum ? $r->value : $r,
                (array) $needles
            ), $roles)) > 0
        );
        $user->forceFill(['id' => 1, 'username' => 'tester']);
        $user->setRelation('karyawan', $karyawan);

        return $user;
    }

    private function bindService(): InvoiceService&Mockery\MockInterface
    {
        $service = Mockery::mock(InvoiceService::class);
        $this->app->instance(InvoiceService::class, $service);

        return $service;
    }

    private function withKlienArTable(callable $callback): mixed
    {
        Schema::create('tb_klien_ar', function ($table) {
            $table->id();
            $table->unsignedBigInteger('karyawan_ar_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        try {
            return $callback();
        } finally {
            Schema::dropIfExists('tb_klien_ar');
        }
    }

    private function itemPayload(int $klienArId, array $overrides = []): array
    {
        return array_merge([
            'klien_ar_id' => $klienArId,
            'tanggal'     => '2026-08-01',
            'saldo_awal'  => 500000,
            'keterangan'  => null,
        ], $overrides);
    }

    // ── RoleHelper: sumber kebenaran akses operasi ──────────────────

    public function test_role_helper_operate_mengizinkan_admin_manager_supervisor_ar(): void
    {
        foreach (['ADMIN', 'MANAGER', 'SUPERVISOR', 'AR'] as $role) {
            $this->assertTrue(
                RoleHelper::canOperateOpeningBalance($this->makeUser([$role])),
                "Role {$role} seharusnya bisa mengelola opening balance"
            );
        }
    }

    public function test_role_helper_operate_menolak_role_ap(): void
    {
        $this->assertFalse(RoleHelper::canOperateOpeningBalance($this->makeUser(['AP'])));
    }

    // ── POST /finance/opening-balance/bulk ────────────────────────────

    public function test_role_ap_tidak_bisa_bulk_create(): void
    {
        $this->withKlienArTable(function () {
            $klienId = KlienAr::query()->insertGetId(['karyawan_ar_id' => 500]);

            $service = $this->bindService();
            $service->shouldNotReceive('createOpeningBalanceBulk');

            $response = $this->actingAs($this->makeUser(['AP']))
                ->postJson('/api/v1/finance/opening-balance/bulk', [
                    'items' => [$this->itemPayload($klienId)],
                ]);

            $response->assertStatus(403);
        });
    }

    public function test_pic_ar_menyertakan_client_bukan_miliknya_ditolak_403_tanpa_write(): void
    {
        $this->withKlienArTable(function () {
            $klienSendiri  = KlienAr::query()->insertGetId(['karyawan_ar_id' => 500]);
            $klienSendiri2 = KlienAr::query()->insertGetId(['karyawan_ar_id' => 500]);
            $klienOrangLain = KlienAr::query()->insertGetId(['karyawan_ar_id' => 600]);

            $service = $this->bindService();
            $service->shouldNotReceive('createOpeningBalanceBulk');

            $karyawan = new Karyawan();
            $karyawan->forceFill(['id' => 500]);

            $response = $this->actingAs($this->makeUser(['AR'], $karyawan))
                ->postJson('/api/v1/finance/opening-balance/bulk', [
                    'items' => [
                        $this->itemPayload($klienSendiri),
                        $this->itemPayload($klienSendiri2),
                        $this->itemPayload($klienOrangLain),
                    ],
                ]);

            $response->assertStatus(403);
        });
    }

    public function test_pic_ar_bisa_bulk_create_untuk_client_miliknya_sendiri(): void
    {
        $this->withKlienArTable(function () {
            $klienA = KlienAr::query()->insertGetId(['karyawan_ar_id' => 500]);
            $klienB = KlienAr::query()->insertGetId(['karyawan_ar_id' => 500]);

            $service = $this->bindService();
            $service->shouldReceive('createOpeningBalanceBulk')
                ->once()
                ->with(Mockery::on(fn ($items) => count($items) === 2))
                ->andReturn([]);

            $karyawan = new Karyawan();
            $karyawan->forceFill(['id' => 500]);

            $response = $this->actingAs($this->makeUser(['AR'], $karyawan))
                ->postJson('/api/v1/finance/opening-balance/bulk', [
                    'items' => [$this->itemPayload($klienA), $this->itemPayload($klienB)],
                ]);

            $response->assertStatus(201);
        });
    }

    public function test_admin_bisa_bulk_create_lintas_client(): void
    {
        $this->withKlienArTable(function () {
            $klienA = KlienAr::query()->insertGetId(['karyawan_ar_id' => 500]);
            $klienB = KlienAr::query()->insertGetId(['karyawan_ar_id' => 600]);

            $service = $this->bindService();
            $service->shouldReceive('createOpeningBalanceBulk')
                ->once()
                ->with(Mockery::on(fn ($items) => count($items) === 2))
                ->andReturn([]);

            $response = $this->actingAs($this->makeUser(['ADMIN']))
                ->postJson('/api/v1/finance/opening-balance/bulk', [
                    'items' => [$this->itemPayload($klienA), $this->itemPayload($klienB)],
                ]);

            $response->assertStatus(201);
        });
    }

    public function test_klien_ar_id_duplikat_dalam_satu_batch_ditolak_422(): void
    {
        $this->withKlienArTable(function () {
            $klienId = KlienAr::query()->insertGetId(['karyawan_ar_id' => 500]);

            $service = $this->bindService();
            $service->shouldNotReceive('createOpeningBalanceBulk');

            $response = $this->actingAs($this->makeUser(['ADMIN']))
                ->postJson('/api/v1/finance/opening-balance/bulk', [
                    'items' => [$this->itemPayload($klienId), $this->itemPayload($klienId)],
                ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['items.0.klien_ar_id']);
        });
    }

    public function test_sum_rincian_tidak_sama_dengan_saldo_awal_ditolak_422(): void
    {
        $this->withKlienArTable(function () {
            $klienId = KlienAr::query()->insertGetId(['karyawan_ar_id' => 500]);

            $service = $this->bindService();
            $service->shouldNotReceive('createOpeningBalanceBulk');

            $response = $this->actingAs($this->makeUser(['ADMIN']))
                ->postJson('/api/v1/finance/opening-balance/bulk', [
                    'items' => [$this->itemPayload($klienId, [
                        'saldo_awal' => 100000,
                        'details'    => [[
                            'no_invoice_asal'      => 'INV-001',
                            'tanggal_invoice_asal' => '2026-07-01',
                            'jumlah_tagihan_asal'  => 100000,
                            'sisa_tagihan_asal'    => 50000,
                        ]],
                    ])],
                ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['items.0.details']);
        });
    }

    public function test_items_kosong_ditolak_422(): void
    {
        $service = $this->bindService();
        $service->shouldNotReceive('createOpeningBalanceBulk');

        $response = $this->actingAs($this->makeUser(['ADMIN']))
            ->postJson('/api/v1/finance/opening-balance/bulk', ['items' => []]);

        $response->assertStatus(422)->assertJsonValidationErrors(['items']);
    }
}
