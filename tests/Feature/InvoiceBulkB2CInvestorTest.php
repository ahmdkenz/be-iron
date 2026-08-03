<?php

namespace Tests\Feature;

use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Models\Invoice;
use App\Models\Investor;
use App\Models\Karyawan;
use App\Models\KlienAr;
use App\Models\Resto;
use App\Models\User;
use App\Support\Enums\RoleEnum;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * Menghindari RefreshDatabase karena migrate:fresh rusak di project ini
 * (lihat project_test_db_migrate_fresh_broken di memory) — InvoiceService
 * di-mock total, model relasi dipasang manual via setRelation() supaya
 * tidak ada lazy-load relasi yang menyentuh DB. Test yang benar-benar butuh
 * DB (403 lintas PIC AR lewat authorizeKlienArOwnership; happy-path "link"
 * yang sekarang INSERT ke tb_bulk_print_tokens via BulkPrintToken::create())
 * membuat tabelnya sendiri ad-hoc via Schema::create lalu drop lagi, tanpa
 * menyentuh migrasi lain.
 */
class InvoiceBulkB2CInvestorTest extends TestCase
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

    private function bindService(): InvoiceService&Mockery\MockInterface
    {
        $service = Mockery::mock(InvoiceService::class);
        $this->app->instance(InvoiceService::class, $service);

        return $service;
    }

    private function makeInvestor(?int $id = 1): ?Investor
    {
        if ($id === null) {
            return null;
        }

        $investor = new Investor();
        $investor->forceFill(['id' => $id, 'nama_investor' => 'PT Investor Contoh']);

        return $investor;
    }

    private function makeResto(?Investor $investor, int $id = 1): Resto
    {
        $resto = new Resto();
        $resto->forceFill([
            'id'          => $id,
            'nama_resto'  => 'Resto A',
            'kode_resto'  => 'RA01',
            'investor_id' => $investor?->id,
        ]);
        $resto->setRelation('investor', $investor);

        return $resto;
    }

    private function makeKlienAr(string $tipeKlien, ?Resto $resto, ?int $karyawanArId = null, int $id = 50): KlienAr
    {
        $klien = new KlienAr();
        $klien->forceFill([
            'id'             => $id,
            'nama_klien'     => 'Klien Anchor',
            'tipe_klien'     => $tipeKlien,
            'no_wa'          => '081234567890',
            'karyawan_ar_id' => $karyawanArId,
            'resto_id'       => $resto?->id,
        ]);
        $klien->setRelation('resto', $resto);
        $klien->setRelation('karyawanAr', null);

        return $klien;
    }

    private function makeAnchorInvoice(KlienAr $klien, bool $isOb = false, int $id = 100): Invoice
    {
        $invoice = new Invoice();
        $invoice->forceFill([
            'id'                 => $id,
            'no_invoice'         => 'INV-001',
            'klien_ar_id'        => $klien->id,
            'is_opening_balance' => $isOb,
            'status'             => 'TERKIRIM',
            'subtotal'           => 1000000,
            'total_pembayaran'   => 0,
            'total_penyesuaian'  => 0,
            'tanggal_invoice'    => '2026-07-10',
        ]);
        $invoice->setRelation('klienAr', $klien);

        return $invoice;
    }

    private function basePayload(int $anchorId = 100): array
    {
        return [
            'anchor_invoice_id' => $anchorId,
            'tanggal_dari'      => '2026-07-01',
            'tanggal_sampai'    => '2026-07-31',
        ];
    }

    public function test_anchor_b2b_ditolak(): void
    {
        $klien  = $this->makeKlienAr('PT', null);
        $anchor = $this->makeAnchorInvoice($klien);

        $service = $this->bindService();
        $service->shouldReceive('findForPrintOrFail')->once()->with(100)->andReturn($anchor);
        $service->shouldNotReceive('getBulkB2CInvestorInvoices');

        $response = $this->actingAs($this->makeUser(['MANAGER']))
            ->postJson('/api/v1/finance/invoices/bulk-b2c-investor/preview', $this->basePayload());

        $response->assertStatus(422);
    }

    public function test_anchor_opening_balance_ditolak(): void
    {
        $resto  = $this->makeResto($this->makeInvestor());
        $klien  = $this->makeKlienAr('RESTO', $resto);
        $anchor = $this->makeAnchorInvoice($klien, isOb: true);

        $service = $this->bindService();
        $service->shouldReceive('findForPrintOrFail')->once()->andReturn($anchor);
        $service->shouldNotReceive('getBulkB2CInvestorInvoices');

        $response = $this->actingAs($this->makeUser(['MANAGER']))
            ->postJson('/api/v1/finance/invoices/bulk-b2c-investor/preview', $this->basePayload());

        $response->assertStatus(422);
    }

    public function test_anchor_tanpa_investor_ditolak(): void
    {
        $resto  = $this->makeResto($this->makeInvestor(null));
        $klien  = $this->makeKlienAr('RESTO', $resto);
        $anchor = $this->makeAnchorInvoice($klien);

        $service = $this->bindService();
        $service->shouldReceive('findForPrintOrFail')->once()->andReturn($anchor);
        $service->shouldNotReceive('getBulkB2CInvestorInvoices');

        $response = $this->actingAs($this->makeUser(['MANAGER']))
            ->postJson('/api/v1/finance/invoices/bulk-b2c-investor/preview', $this->basePayload());

        $response->assertStatus(422);
    }

    public function test_kandidat_lebih_dari_200_ditolak(): void
    {
        $investor = $this->makeInvestor();
        $resto  = $this->makeResto($investor);
        $klien  = $this->makeKlienAr('RESTO', $resto);
        $anchor = $this->makeAnchorInvoice($klien);

        $manyInvoices = collect(range(1, 201))->map(function ($i) use ($klien) {
            return $this->makeAnchorInvoice($klien, id: $i);
        });

        $service = $this->bindService();
        $service->shouldReceive('findForPrintOrFail')->once()->andReturn($anchor);
        $service->shouldReceive('resolveMatchingInvestorIds')->once()->andReturn([$investor->id]);
        $service->shouldReceive('getBulkB2CInvestorInvoices')->once()->andReturn($manyInvoices);

        $response = $this->actingAs($this->makeUser(['MANAGER']))
            ->postJson('/api/v1/finance/invoices/bulk-b2c-investor/preview', $this->basePayload());

        $response->assertStatus(422);
    }

    public function test_preview_happy_path_tidak_menyertakan_share_url(): void
    {
        $investor = $this->makeInvestor();
        $resto  = $this->makeResto($investor);
        $klien  = $this->makeKlienAr('RESTO', $resto);
        $anchor = $this->makeAnchorInvoice($klien);
        $invoices = collect([$anchor]);

        $service = $this->bindService();
        $service->shouldReceive('findForPrintOrFail')->once()->andReturn($anchor);
        $service->shouldReceive('resolveMatchingInvestorIds')->once()->andReturn([$investor->id]);
        $service->shouldReceive('getBulkB2CInvestorInvoices')->once()->andReturn($invoices);

        $response = $this->actingAs($this->makeUser(['MANAGER']))
            ->postJson('/api/v1/finance/invoices/bulk-b2c-investor/preview', $this->basePayload());

        $response->assertOk()
            ->assertJsonPath('data.total_invoice', 1)
            ->assertJsonPath('data.total_resto', 1)
            ->assertJsonPath('data.investor.nama_investor', 'PT Investor Contoh')
            ->assertJsonMissingPath('data.share_url');
    }

    public function test_link_happy_path_menyertakan_share_url(): void
    {
        // buildBulkB2CInvestorPayload() sekarang benar-benar INSERT ke
        // tb_bulk_print_tokens (bukan cuma encode string) — tabel ini tidak
        // ada di sqlite :memory: bawaan test (migrate:fresh rusak, lihat
        // docblock class), jadi dibuat ad-hoc di sini persis pola tb_klien_ar
        // di test_pic_ar_lain_ditolak_403().
        Schema::create('tb_bulk_print_tokens', function ($table) {
            $table->uuid('token')->primary();
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();
        });

        try {
            $investor = $this->makeInvestor();
            $resto  = $this->makeResto($investor);
            $klien  = $this->makeKlienAr('RESTO', $resto);
            $anchor = $this->makeAnchorInvoice($klien);
            $invoices = collect([$anchor]);

            $service = $this->bindService();
            $service->shouldReceive('findForPrintOrFail')->once()->andReturn($anchor);
            $service->shouldReceive('resolveMatchingInvestorIds')->once()->andReturn([$investor->id]);
            $service->shouldReceive('getBulkB2CInvestorInvoices')->once()->andReturn($invoices);

            $response = $this->actingAs($this->makeUser(['MANAGER']))
                ->postJson('/api/v1/finance/invoices/bulk-b2c-investor/link', $this->basePayload());

            $response->assertOk()->assertJsonPath('data.total_invoice', 1);

            $shareUrl = $response->json('data.share_url');
            $this->assertNotEmpty($shareUrl);
            $this->assertStringContainsString('/invoices/bulk-print/', $shareUrl);
        } finally {
            Schema::dropIfExists('tb_bulk_print_tokens');
        }
    }

    public function test_investor_terduplikat_per_outlet_tetap_digabung(): void
    {
        // Meniru kasus nyata "ARIS MUNANDAR S.Kom": 2 outlet, 2 baris tb_investor
        // terpisah (investor_id beda) tapi merepresentasikan 1 investor yang sama.
        $investorAnchor = $this->makeInvestor(1);
        $investorLain   = $this->makeInvestor(2);

        $restoAnchor = $this->makeResto($investorAnchor, id: 1);
        $restoLain   = $this->makeResto($investorLain, id: 2);

        $klienAnchor = $this->makeKlienAr('RESTO', $restoAnchor, id: 50);
        $klienLain   = $this->makeKlienAr('RESTO', $restoLain, id: 51);

        $anchor = $this->makeAnchorInvoice($klienAnchor);
        $invoiceLain = $this->makeAnchorInvoice($klienLain, id: 200);

        $service = $this->bindService();
        $service->shouldReceive('findForPrintOrFail')->once()->andReturn($anchor);
        $service->shouldReceive('resolveMatchingInvestorIds')
            ->once()
            ->with(\Mockery::on(fn($inv) => $inv->id === 1))
            ->andReturn([1, 2]);
        $service->shouldReceive('getBulkB2CInvestorInvoices')
            ->once()
            ->with(\Mockery::on(fn($ids) => $ids === [1, 2]), \Mockery::any(), \Mockery::any(), \Mockery::any())
            ->andReturn(collect([$anchor, $invoiceLain]));

        $response = $this->actingAs($this->makeUser(['MANAGER']))
            ->postJson('/api/v1/finance/invoices/bulk-b2c-investor/preview', $this->basePayload());

        $response->assertOk()
            ->assertJsonPath('data.total_invoice', 2)
            ->assertJsonPath('data.total_resto', 2);
    }

    public function test_pic_ar_lain_ditolak_403(): void
    {
        Schema::create('tb_klien_ar', function ($table) {
            $table->id();
            $table->unsignedBigInteger('karyawan_ar_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        try {
            $ownerKlienArId = KlienAr::query()->insertGetId(['karyawan_ar_id' => 999]);

            $resto  = $this->makeResto($this->makeInvestor());
            $klien  = $this->makeKlienAr('RESTO', $resto, karyawanArId: 999, id: $ownerKlienArId);
            $anchor = $this->makeAnchorInvoice($klien);

            $service = $this->bindService();
            $service->shouldReceive('findForPrintOrFail')->once()->andReturn($anchor);
            $service->shouldNotReceive('getBulkB2CInvestorInvoices');

            $karyawanLain = new Karyawan();
            $karyawanLain->forceFill(['id' => 5]);

            $response = $this->actingAs($this->makeUser(['AR'], $karyawanLain))
                ->postJson('/api/v1/finance/invoices/bulk-b2c-investor/preview', $this->basePayload());

            $response->assertStatus(403);
        } finally {
            Schema::dropIfExists('tb_klien_ar');
        }
    }
}
