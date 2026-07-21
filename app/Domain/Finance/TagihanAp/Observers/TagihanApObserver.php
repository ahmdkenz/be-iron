<?php

namespace App\Domain\Finance\TagihanAp\Observers;

use App\Domain\Finance\ApShz360Sync\Services\ApShz360ImportService;
use App\Domain\Finance\EndingBalanceAp\Services\EndingBalanceApService;
use App\Models\TagihanAp;
use Illuminate\Support\Facades\DB;

class TagihanApObserver
{
    public function __construct(
        private readonly ApShz360ImportService $importService,
        private readonly EndingBalanceApService $ebApService,
    ) {}

    public function created(TagihanAp $tagihan): void
    {
        $this->syncEb($tagihan);
    }

    public function updated(TagihanAp $tagihan): void
    {
        // Menangkap approval opening balance & recalculate() dari PembayaranApService
        $this->syncEb($tagihan);
    }

    public function deleted(TagihanAp $tagihan): void
    {
        if (! $tagihan->ap_shz360_receipt_import_id) {
            return;
        }

        $this->importService->revertReceiptAfterTagihanDeleted($tagihan->ap_shz360_receipt_import_id);
    }

    private function syncEb(TagihanAp $tagihan): void
    {
        if (!$tagihan->tanggal_tagihan || !$tagihan->vendor_ap_id || !$tagihan->perusahaan_id) {
            return;
        }

        // Opening Balance masuk EB hanya setelah diapprove, bukan saat dibuat/diupdate
        if ($tagihan->is_opening_balance && $tagihan->approval_status !== 'APPROVED') {
            return;
        }

        $vendorId     = $tagihan->vendor_ap_id;
        $perusahaanId = $tagihan->perusahaan_id;
        $periodeAwal  = $tagihan->tanggal_tagihan->copy()->startOfMonth()->toDateString();
        $periodeAkhir = $tagihan->tanggal_tagihan->copy()->endOfMonth()->toDateString();
        $userId       = auth()->id() ?? $tagihan->created_by;

        DB::afterCommit(
            fn() => $this->ebApService->syncEbForVendor($vendorId, $perusahaanId, $periodeAwal, $periodeAkhir, $userId)
        );
    }
}
