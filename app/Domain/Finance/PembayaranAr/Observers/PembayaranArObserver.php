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

    public function updated(PembayaranAr $pembayaran): void
    {
        // Jika tanggal pembayaran berubah, sync periode LAMA agar pembayaran
        // yang "pindah bulan" dikeluarkan dari EB bulan asal.
        if ($pembayaran->wasChanged('tanggal_pembayaran')) {
            $oldTanggal      = Carbon::parse($pembayaran->getOriginal('tanggal_pembayaran'));
            $oldPeriodeAwal  = $oldTanggal->copy()->startOfMonth()->toDateString();
            $oldPeriodeAkhir = $oldTanggal->copy()->endOfMonth()->toDateString();
            $invoice         = $pembayaran->invoice;

            if ($invoice && !$invoice->is_opening_balance) {
                $userId = auth()->id() ?? $pembayaran->updated_by;
                DB::afterCommit(
                    fn() => $this->ebService->syncEbForKlien($invoice->klien_ar_id, $oldPeriodeAwal, $oldPeriodeAkhir, $userId)
                );
            }
        }

        // Sync periode baru (jumlah berubah atau tanggal pindah ke bulan ini)
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
