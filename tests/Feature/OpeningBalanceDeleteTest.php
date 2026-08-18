<?php

namespace Tests\Feature;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceService;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Notification\Services\FinanceNotificationService;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * InvoiceService::deleteOpeningBalance() — jalur hapus permanen (hard delete) untuk
 * Opening Balance yang salah ter-import/dicatat (lihat plan "Perbaikan Re-Import
 * Opening Balance AR"). Menghindari RefreshDatabase karena migrate:fresh rusak di
 * project ini (lihat project_test_db_migrate_fresh_broken di memory) — tabel dibuat
 * ad-hoc via Schema::create lalu di-drop lagi, persis pola OpeningBalanceBulkStoreTest.
 * EndingBalanceService & FinanceNotificationService di-mock supaya tidak perlu tabel
 * tb_ending_balance sungguhan — deleteOpeningBalance() sendiri tidak memanggil
 * notifikasi apapun, tapi InvoiceService butuh keduanya lewat constructor DI.
 */
class OpeningBalanceDeleteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tb_invoice', function ($table) {
            $table->id();
            $table->string('no_invoice')->nullable();
            $table->boolean('is_opening_balance')->default(false);
            $table->unsignedBigInteger('klien_ar_id')->nullable();
            $table->date('tanggal_invoice')->nullable();
            $table->string('status')->nullable();
            $table->string('approval_status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tb_opening_balance_detail', function ($table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->string('no_invoice_asal')->nullable();
            $table->date('tanggal_invoice_asal')->nullable();
            $table->timestamps();
        });

        Schema::create('tb_pembayaran_ar', function ($table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->timestamps();
        });

        Schema::create('tb_invoice_approval_logs', function ($table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->string('action', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tb_invoice_approval_logs');
        Schema::dropIfExists('tb_pembayaran_ar');
        Schema::dropIfExists('tb_opening_balance_detail');
        Schema::dropIfExists('tb_invoice');
        Mockery::close();
        parent::tearDown();
    }

    private function makeService(): InvoiceService
    {
        $endingBalance = Mockery::mock(EndingBalanceService::class);
        $endingBalance->shouldReceive('isLockedForPeriod')->andReturn(false);
        $this->app->instance(EndingBalanceService::class, $endingBalance);

        $notif = Mockery::mock(FinanceNotificationService::class)->shouldIgnoreMissing();
        $this->app->instance(FinanceNotificationService::class, $notif);

        return $this->app->make(InvoiceService::class);
    }

    private function actingAsAdmin(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => 1, 'username' => 'tester']);
        $this->actingAs($user);
    }

    private function insertOb(array $overrides = []): int
    {
        return Invoice::query()->insertGetId(array_merge([
            'no_invoice' => 'OB-TEST-'.uniqid(),
            'is_opening_balance' => true,
            'klien_ar_id' => 10,
            'tanggal_invoice' => '2026-08-01',
            'status' => 'TERKIRIM',
            'approval_status' => 'APPROVED',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_hapus_ob_tanpa_pembayaran_berhasil_hard_delete(): void
    {
        $invoiceId = $this->insertOb();
        \DB::table('tb_opening_balance_detail')->insert([
            'invoice_id' => $invoiceId,
            'no_invoice_asal' => 'INV-ASAL-001',
            'tanggal_invoice_asal' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsAdmin();
        $invoice = Invoice::findOrFail($invoiceId);

        $this->makeService()->deleteOpeningBalance($invoice, 'Salah import, data tidak valid');

        $this->assertDatabaseMissing('tb_invoice', ['id' => $invoiceId]);
        $this->assertDatabaseMissing('tb_opening_balance_detail', ['invoice_id' => $invoiceId]);
        $this->assertDatabaseHas('tb_invoice_approval_logs', [
            'invoice_id' => $invoiceId,
            'action' => 'DELETED',
            'note' => 'Salah import, data tidak valid',
        ]);
    }

    public function test_hapus_ob_dengan_pembayaran_diblokir_dan_tidak_menghapus_apapun(): void
    {
        $invoiceId = $this->insertOb();
        \DB::table('tb_pembayaran_ar')->insert([
            'invoice_id' => $invoiceId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsAdmin();
        $invoice = Invoice::findOrFail($invoiceId);

        try {
            $this->makeService()->deleteOpeningBalance($invoice, 'Coba hapus meski sudah dibayar');
            $this->fail('Seharusnya melempar HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertDatabaseHas('tb_invoice', ['id' => $invoiceId]);
    }

    public function test_hapus_invoice_yang_bukan_opening_balance_ditolak(): void
    {
        $invoiceId = $this->insertOb(['is_opening_balance' => false, 'no_invoice' => 'INV-REGULER-001']);

        $this->actingAsAdmin();
        $invoice = Invoice::findOrFail($invoiceId);

        try {
            $this->makeService()->deleteOpeningBalance($invoice, 'Bukan OB');
            $this->fail('Seharusnya melempar HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertDatabaseHas('tb_invoice', ['id' => $invoiceId]);
    }
}
