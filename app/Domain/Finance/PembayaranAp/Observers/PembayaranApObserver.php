<?php

namespace App\Domain\Finance\PembayaranAp\Observers;

use App\Domain\Finance\EndingBalanceAp\Services\EndingBalanceApService;
use App\Models\PembayaranAp;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PembayaranApObserver
{
    public function __construct(private readonly EndingBalanceApService $ebApService) {}

    public function created(PembayaranAp $pembayaran): void
    {
        $this->syncEb($pembayaran, $this->resolvePairs($pembayaran));
    }

    /**
     * tb_pembayaran_ap_items cascade-delete di level DB begitu header dihapus,
     * jadi pasangan vendor+perusahaan wajib diambil di sini (SEBELUM delete
     * benar-benar terjadi) — deleted() sudah terlambat, items sudah hilang.
     */
    public function deleting(PembayaranAp $pembayaran): void
    {
        $this->syncEb($pembayaran, $this->resolvePairs($pembayaran));
    }

    private function resolvePairs(PembayaranAp $pembayaran): Collection
    {
        return DB::table('tb_pembayaran_ap_items as pai')
            ->join('tb_tagihan_ap as ta', 'pai.tagihan_ap_id', '=', 'ta.id')
            ->where('pai.pembayaran_ap_id', $pembayaran->id)
            ->select('pai.vendor_ap_id', 'ta.perusahaan_id')
            ->distinct()
            ->get();
    }

    private function syncEb(PembayaranAp $pembayaran, Collection $pairs): void
    {
        if (!$pembayaran->tanggal_pembayaran || $pairs->isEmpty()) {
            return;
        }

        $tanggal      = Carbon::parse($pembayaran->tanggal_pembayaran);
        $periodeAwal  = $tanggal->copy()->startOfMonth()->toDateString();
        $periodeAkhir = $tanggal->copy()->endOfMonth()->toDateString();
        $userId       = auth()->id() ?? $pembayaran->created_by;

        foreach ($pairs as $pair) {
            $vendorId     = (int) $pair->vendor_ap_id;
            $perusahaanId = (int) $pair->perusahaan_id;

            DB::afterCommit(
                fn() => $this->ebApService->syncEbForVendor($vendorId, $perusahaanId, $periodeAwal, $periodeAkhir, $userId)
            );
        }
    }
}
