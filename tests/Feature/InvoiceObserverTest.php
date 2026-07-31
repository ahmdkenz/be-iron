<?php

namespace Tests\Feature;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceService;
use App\Domain\Finance\EndingBalance\Services\EndingBalanceSyncBatcher;
use App\Domain\Finance\Invoice\Observers\InvoiceObserver;
use App\Models\Invoice;
use Tests\TestCase;

/**
 * Menguji InvoiceObserver::syncEb() secara terisolasi (tanpa DB, tanpa
 * Invoice::save() sungguhan) — perilaku guard existing (OB / tanpa tanggal)
 * dan percabangan baru: sync langsung vs dedup lewat EndingBalanceSyncBatcher
 * saat mode batching aktif.
 */
class InvoiceObserverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EndingBalanceSyncBatcher::resetForTesting();
    }

    protected function tearDown(): void
    {
        EndingBalanceSyncBatcher::resetForTesting();
        parent::tearDown();
    }

    private function makeInvoice(int $klienArId, string $tanggalInvoice, bool $isOpeningBalance = false): Invoice
    {
        $invoice = new Invoice();
        $invoice->forceFill([
            'klien_ar_id'        => $klienArId,
            'tanggal_invoice'    => $tanggalInvoice,
            'is_opening_balance' => $isOpeningBalance,
            'created_by'         => 1,
        ]);

        return $invoice;
    }

    public function test_created_syncs_immediately_when_batching_not_active(): void
    {
        $mock = $this->createMock(EndingBalanceService::class);
        $mock->expects($this->once())
            ->method('syncEbForKlien')
            ->with(10, '2026-07-01', '2026-07-31', $this->anything());

        $observer = new InvoiceObserver($mock);
        $observer->created($this->makeInvoice(10, '2026-07-15'));
    }

    public function test_updated_syncs_immediately_when_batching_not_active(): void
    {
        $mock = $this->createMock(EndingBalanceService::class);
        $mock->expects($this->once())->method('syncEbForKlien');

        $observer = new InvoiceObserver($mock);
        $observer->updated($this->makeInvoice(10, '2026-07-15'));
    }

    public function test_opening_balance_never_syncs(): void
    {
        $mock = $this->createMock(EndingBalanceService::class);
        $mock->expects($this->never())->method('syncEbForKlien');

        $observer = new InvoiceObserver($mock);
        $observer->created($this->makeInvoice(10, '2026-07-15', isOpeningBalance: true));
    }

    public function test_invoice_without_date_never_syncs(): void
    {
        $mock = $this->createMock(EndingBalanceService::class);
        $mock->expects($this->never())->method('syncEbForKlien');

        $observer = new InvoiceObserver($mock);
        $invoice = new Invoice();
        $invoice->forceFill(['klien_ar_id' => 10, 'created_by' => 1]);
        $observer->created($invoice);
    }

    public function test_within_batch_multiple_invoices_same_period_dedup_into_single_sync(): void
    {
        $calls = [];
        $mock = $this->createMock(EndingBalanceService::class);
        $mock->method('syncEbForKlien')->willReturnCallback(function (...$args) use (&$calls) {
            $calls[] = $args;
        });
        $this->app->instance(EndingBalanceService::class, $mock);

        $observer = new InvoiceObserver($mock);
        $invoiceA = $this->makeInvoice(10, '2026-07-05');
        $invoiceB = $this->makeInvoice(10, '2026-07-20');

        EndingBalanceSyncBatcher::run(function () use ($observer, $invoiceA, $invoiceB, &$calls) {
            $observer->created($invoiceA);
            $observer->updated($invoiceA);
            $observer->created($invoiceB);

            $this->assertCount(0, $calls, 'sync harus ditunda selama batch masih aktif');
        });

        $this->assertCount(1, $calls, 'invoice berbeda pada klien+bulan yang sama harus di-dedup jadi 1 sync');
        $this->assertSame(10, $calls[0][0]);
        $this->assertSame('2026-07-01', $calls[0][1]);
        $this->assertSame('2026-07-31', $calls[0][2]);
    }

    public function test_within_batch_different_periods_flush_separately(): void
    {
        $mock = $this->createMock(EndingBalanceService::class);
        $mock->expects($this->exactly(2))->method('syncEbForKlien');
        $this->app->instance(EndingBalanceService::class, $mock);

        $observer = new InvoiceObserver($mock);

        EndingBalanceSyncBatcher::run(function () use ($observer) {
            $observer->created($this->makeInvoice(10, '2026-07-05'));
            $observer->created($this->makeInvoice(10, '2026-08-05'));
        });
    }
}
