<?php

namespace App\Domain\Finance\PembayaranAr\Observers;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceService;
use App\Models\PembayaranAr;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PembayaranArObserver
{
    public function __construct(private readonly EndingBalanceService $ebService) {}

    public function created(PembayaranAr $pembayaran): void
    {
        $this->syncEb($pembayaran);
    }

    public function deleted(PembayaranAr $pembayaran): void
    {
        $this->syncEb($pembayaran);
    }

    private function syncEb(PembayaranAr $pembayaran): void
    {
        if (!$pembayaran->tanggal_pembayaran) {
            return;
        }

        $invoice = $pembayaran->invoice;
        if (!$invoice || $invoice->is_opening_balance) {
            return;
        }

        // Periode EB = kalender bulan dari tanggal_pembayaran
        // karena computeComponents menghitung pembayaran berdasarkan tanggal_pembayaran
        $tanggal      = Carbon::parse($pembayaran->tanggal_pembayaran);
        $periodeAwal  = $tanggal->copy()->startOfMonth()->toDateString();
        $periodeAkhir = $tanggal->copy()->endOfMonth()->toDateString();
        $userId       = auth()->id() ?? $pembayaran->created_by;

        DB::afterCommit(
            fn() => $this->ebService->syncEbForKlien($invoice->klien_ar_id, $periodeAwal, $periodeAkhir, $userId)
        );
    }
}
