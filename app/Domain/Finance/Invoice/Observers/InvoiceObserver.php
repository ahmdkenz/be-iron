<?php

namespace App\Domain\Finance\Invoice\Observers;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceService;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class InvoiceObserver
{
    public function __construct(private readonly EndingBalanceService $ebService) {}

    public function created(Invoice $invoice): void
    {
        $this->syncEb($invoice);
    }

    public function updated(Invoice $invoice): void
    {
        // Menangkap Post-process import yang update total_tagihan setelah items ditambahkan
        $this->syncEb($invoice);
    }

    private function syncEb(Invoice $invoice): void
    {
        // Opening Balance masuk EB hanya setelah diapprove, bukan saat dibuat/diupdate
        if ($invoice->is_opening_balance) {
            return;
        }

        if (!$invoice->tanggal_invoice) {
            return;
        }

        $klienId      = $invoice->klien_ar_id;
        $periodeAwal  = $invoice->tanggal_invoice->copy()->startOfMonth()->toDateString();
        $periodeAkhir = $invoice->tanggal_invoice->copy()->endOfMonth()->toDateString();
        $userId       = auth()->id() ?? $invoice->created_by;

        // DB::afterCommit memastikan computeComponents berjalan setelah semua
        // data dalam transaksi (items, totals) sudah ter-commit ke database
        DB::afterCommit(
            fn() => $this->ebService->syncEbForKlien($klienId, $periodeAwal, $periodeAkhir, $userId)
        );
    }
}
