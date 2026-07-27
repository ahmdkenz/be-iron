<?php

namespace Tests\Feature;

use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\PembayaranAp\Services\PembayaranApService;
use App\Domain\Finance\PembayaranAr\Services\PembayaranArService;
use App\Domain\Finance\RekonsiliasiBankStatement\Services\BankStatementService;
use App\Models\BankStatementDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * Menghindari RefreshDatabase (lihat project_test_db_migrate_fresh_broken di memory)
 * — tb_resto, tb_klien_ar, tb_invoice dibuat ad-hoc via Schema::create lalu di-drop
 * lagi, tanpa menyentuh migrasi lain. Dependency BankStatementService yang tidak
 * dipakai method getInvoiceCandidatesForNewPayment() di-mock kosong.
 */
class BankStatementInvoiceCandidatesSearchRestoTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('tb_invoice');
        Schema::dropIfExists('tb_klien_ar');
        Schema::dropIfExists('tb_resto');
        Mockery::close();
        parent::tearDown();
    }

    private function service(): BankStatementService
    {
        return new BankStatementService(
            Mockery::mock(PembayaranArService::class),
            Mockery::mock(PembayaranApService::class),
            Mockery::mock(InvoiceService::class),
        );
    }

    private function seedData(): void
    {
        Schema::create('tb_resto', function ($table) {
            $table->id();
            $table->string('nama_resto');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tb_klien_ar', function ($table) {
            $table->id();
            $table->string('nama_klien');
            $table->unsignedBigInteger('resto_id')->nullable();
            $table->unsignedBigInteger('karyawan_ar_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tb_invoice', function ($table) {
            $table->id();
            $table->string('no_invoice');
            $table->date('tanggal_invoice')->nullable();
            $table->unsignedBigInteger('klien_ar_id')->nullable();
            $table->unsignedBigInteger('resto_id')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('total_pembayaran', 15, 2)->default(0);
            $table->decimal('total_penyesuaian', 15, 2)->default(0);
            $table->decimal('total_tagihan', 15, 2)->default(0);
            $table->decimal('sisa_tagihan', 15, 2)->default(0);
            $table->string('status')->default('TERKIRIM');
            $table->string('approval_status')->nullable();
            $table->boolean('is_opening_balance')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        // Klien 1 (PT) pakai resto langsung di klien_ar (pola B2B lama);
        // Klien 2 dibiarkan tanpa resto_id di klien_ar supaya kasus resto
        // langsung di invoice (pola B2C multi-outlet) ikut ter-cover.
        DB::table('tb_resto')->insert([
            ['id' => 1, 'nama_resto' => 'Resto Alpha', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'nama_resto' => 'Resto Beta', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('tb_klien_ar')->insert([
            ['id' => 1, 'nama_klien' => 'Klien Satu', 'resto_id' => 1, 'karyawan_ar_id' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'nama_klien' => 'Klien Dua', 'resto_id' => null, 'karyawan_ar_id' => 20, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('tb_invoice')->insert([
            [
                'id' => 1, 'no_invoice' => 'INV-001', 'tanggal_invoice' => '2026-07-01',
                'klien_ar_id' => 1, 'resto_id' => null, 'subtotal' => 100000, 'total_tagihan' => 100000,
                'sisa_tagihan' => 100000, 'status' => 'TERKIRIM', 'approval_status' => null,
                'is_opening_balance' => false, 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => 2, 'no_invoice' => 'OB-001', 'tanggal_invoice' => '2026-06-01',
                'klien_ar_id' => 2, 'resto_id' => null, 'subtotal' => 0, 'total_tagihan' => 500000,
                'sisa_tagihan' => 500000, 'status' => 'TERKIRIM', 'approval_status' => 'APPROVED',
                'is_opening_balance' => true, 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => 3, 'no_invoice' => 'OB-002', 'tanggal_invoice' => '2026-06-05',
                'klien_ar_id' => 2, 'resto_id' => null, 'subtotal' => 0, 'total_tagihan' => 300000,
                'sisa_tagihan' => 300000, 'status' => 'TERKIRIM', 'approval_status' => 'PENDING',
                'is_opening_balance' => true, 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                // Invoice B2C dgn resto langsung di invoice (bukan lewat klien_ar).
                'id' => 4, 'no_invoice' => 'INV-004', 'tanggal_invoice' => '2026-07-02',
                'klien_ar_id' => 2, 'resto_id' => 2, 'subtotal' => 200000, 'total_tagihan' => 200000,
                'sisa_tagihan' => 200000, 'status' => 'TERKIRIM', 'approval_status' => null,
                'is_opening_balance' => false, 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
    }

    public function test_search_regular_by_nama_resto_lewat_klien_ar(): void
    {
        $this->seedData();

        $result = $this->service()->getInvoiceCandidatesForNewPayment(
            new BankStatementDetail(),
            search: 'Alpha',
            type: 'regular',
        );

        $this->assertSame(1, $result->total());
        $item = $result->items()[0];
        $this->assertSame('INV-001', $item['no_invoice']);
        $this->assertSame('Resto Alpha', $item['nama_resto']);
    }

    public function test_search_regular_by_nama_resto_langsung_di_invoice(): void
    {
        $this->seedData();

        $result = $this->service()->getInvoiceCandidatesForNewPayment(
            new BankStatementDetail(),
            search: 'Beta',
            type: 'regular',
        );

        $this->assertSame(1, $result->total());
        $item = $result->items()[0];
        $this->assertSame('INV-004', $item['no_invoice']);
        $this->assertSame('Resto Beta', $item['nama_resto']);
    }

    public function test_search_ob_approved_tidak_ikut_kebawa_resto_lain(): void
    {
        $this->seedData();

        $result = $this->service()->getInvoiceCandidatesForNewPayment(
            new BankStatementDetail(),
            search: 'Alpha',
            type: 'ob',
        );

        $this->assertSame(0, $result->total());
    }

    public function test_ob_belum_approved_tidak_muncul(): void
    {
        $this->seedData();

        $result = $this->service()->getInvoiceCandidatesForNewPayment(
            new BankStatementDetail(),
            search: null,
            type: 'ob',
        );

        $noInvoices = collect($result->items())->pluck('no_invoice');
        $this->assertTrue($noInvoices->contains('OB-001'));
        $this->assertFalse($noInvoices->contains('OB-002'));
    }

    public function test_search_lama_no_invoice_dan_nama_klien_tetap_jalan(): void
    {
        $this->seedData();

        $byNoInvoice = $this->service()->getInvoiceCandidatesForNewPayment(
            new BankStatementDetail(),
            search: 'INV-001',
            type: 'regular',
        );
        $this->assertSame(1, $byNoInvoice->total());

        $byNamaKlien = $this->service()->getInvoiceCandidatesForNewPayment(
            new BankStatementDetail(),
            search: 'Klien Satu',
            type: 'regular',
        );
        $this->assertSame(1, $byNamaKlien->total());
    }

    public function test_pic_ar_hanya_lihat_invoice_resto_miliknya(): void
    {
        $this->seedData();

        // karyawan_ar_id 10 = pemilik Klien Satu (Resto Alpha) saja, bukan
        // Klien Dua (Resto Beta) — search umum "Resto" harusnya cuma balik
        // invoice milik PIC AR ybs meski nama_resto lain juga cocok.
        $result = $this->service()->getInvoiceCandidatesForNewPayment(
            new BankStatementDetail(),
            search: 'Resto',
            type: null,
            picArKaryawanId: 10,
        );

        $noInvoices = collect($result->items())->pluck('no_invoice');
        $this->assertTrue($noInvoices->contains('INV-001'));
        $this->assertFalse($noInvoices->contains('INV-004'));
        $this->assertFalse($noInvoices->contains('OB-001'));
    }
}
