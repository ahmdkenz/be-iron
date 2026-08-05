<?php

namespace App\Domain\Finance\Invoice\Observers;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceService;
use App\Domain\Finance\EndingBalance\Services\EndingBalanceSyncBatcher;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        DB::afterCommit(function () use ($klienId, $periodeAwal, $periodeAkhir, $userId) {
            // Saat import bulk (EndingBalanceSyncBatcher::run() aktif), tunda sync
            // dan cukup catat kunci klien+periode agar di-flush sekali di akhir batch
            // alih-alih recompute penuh (+cascade 6 bulan) berulang per invoice.
            if (EndingBalanceSyncBatcher::isActive()) {
                EndingBalanceSyncBatcher::collect($klienId, $periodeAwal, $periodeAkhir, $userId);
                return;
            }

            // Sengaja try/catch (meniru EndingBalanceSyncBatcher::flush()) — closure ini
            // jalan lewat DB::afterCommit SETELAH transaksi invoice sudah commit, jadi
            // exception di sini tidak boleh membuat request tampak gagal padahal datanya
            // sudah tersimpan benar.
            try {
                $this->ebService->syncEbForKlien($klienId, $periodeAwal, $periodeAkhir, $userId);
            } catch (\Throwable $e) {
                Log::error('InvoiceObserver: gagal sync EB', [
                    'klien_ar_id'   => $klienId,
                    'periode_awal'  => $periodeAwal,
                    'periode_akhir' => $periodeAkhir,
                    'error'         => $e->getMessage(),
                ]);
            }
        });
    }
}
