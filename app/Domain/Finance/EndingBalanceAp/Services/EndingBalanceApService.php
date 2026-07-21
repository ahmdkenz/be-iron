<?php

namespace App\Domain\Finance\EndingBalanceAp\Services;

use App\Models\EndingBalanceAp;
use App\Models\TagihanAp;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EndingBalanceApService
{
    /**
     * Apakah tanggal tertentu untuk vendor+perusahaan berada dalam periode
     * Ending Balance AP yang berstatus LOCKED. Dipakai sebagai guard sebelum
     * mengizinkan edit/hapus Tagihan AP (termasuk Opening Balance AP).
     */
    public function isLockedForPeriod(int $vendorApId, int $perusahaanId, string $tanggal): bool
    {
        return EndingBalanceAp::where('vendor_ap_id', $vendorApId)
            ->where('perusahaan_id', $perusahaanId)
            ->where('status', 'LOCKED')
            ->where('periode_awal', '<=', $tanggal)
            ->where('periode_akhir', '>=', $tanggal)
            ->exists();
    }

    /**
     * Paginated list of ending balances AP with filters.
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        return EndingBalanceAp::with(['vendorAp', 'perusahaan', 'createdBy'])
            ->when($filters['vendor_ap_id'] ?? null, fn($q, $v) => $q->where('vendor_ap_id', $v))
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('perusahaan_id', $v))
            ->when($filters['pic_ap_karyawan_id'] ?? null, fn($q, $v) => $q->whereHas(
                'vendorAp',
                fn($q2) => $q2->where('karyawan_ap_id', $v)
            ))
            ->when($filters['status'] ?? null, fn($q, $v) => $q->where('status', $v))
            ->when($filters['periode_awal'] ?? null, fn($q, $v) => $q->where('periode_awal', '>=', $v))
            ->when($filters['periode_akhir'] ?? null, fn($q, $v) => $q->where('periode_akhir', '<=', $v))
            ->orderByDesc('periode_akhir')
            ->orderBy('vendor_ap_id')
            ->paginate($perPage);
    }

    /**
     * Lock (tutup periode) a single ending balance AP row.
     */
    public function lock(EndingBalanceAp $eb, int $userId): EndingBalanceAp
    {
        abort_if($eb->isLocked(), 422, 'Ending balance ini sudah dikunci.');

        $eb->update([
            'status'     => 'LOCKED',
            'locked_by'  => $userId,
            'locked_at'  => now(),
            'updated_by' => $userId,
        ]);

        return $eb->fresh(['vendorAp', 'perusahaan', 'koreksi']);
    }

    /**
     * Unlock (buka kembali) a LOCKED ending balance AP so its period can be re-hitung.
     */
    public function unlock(EndingBalanceAp $eb, int $userId): EndingBalanceAp
    {
        abort_if(!$eb->isLocked(), 422, 'Ending balance ini belum dikunci.');

        $eb->update([
            'status'     => 'DRAFT',
            'locked_by'  => null,
            'locked_at'  => null,
            'updated_by' => $userId,
        ]);

        return $eb->fresh(['vendorAp', 'perusahaan', 'koreksi']);
    }

    /**
     * Recalculate components (saldo_awal, tagihan_masuk, pembayaran) for a DRAFT EB.
     */
    public function recalculate(EndingBalanceAp $eb, int $userId): EndingBalanceAp
    {
        abort_if($eb->isLocked(), 422, 'Tidak bisa menghitung ulang EB yang sudah dikunci.');

        $from = Carbon::parse($eb->periode_awal)->startOfDay();
        $to   = Carbon::parse($eb->periode_akhir)->endOfDay();

        [$saldoAwal, $tagihanMasuk, $pembayaran] = $this->computeComponents($eb->vendor_ap_id, $eb->perusahaan_id, $from, $to);
        $saldoAkhirSistem = max(0, $saldoAwal + $tagihanMasuk - $pembayaran);

        $eb->update([
            'saldo_awal'         => $saldoAwal,
            'tagihan_masuk'      => $tagihanMasuk,
            'pembayaran'         => $pembayaran,
            'saldo_akhir_sistem' => $saldoAkhirSistem,
            'saldo_akhir_final'  => $this->finalWithKoreksi($eb, $saldoAkhirSistem),
            'updated_by'         => $userId,
        ]);

        return $eb->fresh(['vendorAp', 'perusahaan']);
    }

    /**
     * Recompute saldo_akhir_final from approved corrections and persist.
     * Called every time a correction changes to APPROVED.
     */
    public function recomputeFinal(EndingBalanceAp $eb): void
    {
        $eb->update([
            'saldo_akhir_final' => $this->finalWithKoreksi($eb, (float) $eb->saldo_akhir_sistem),
            'updated_by'        => auth()->id(),
        ]);
    }

    /**
     * Saldo akhir final = saldo sistem + total koreksi yang sudah di-approve (floored at 0).
     */
    private function finalWithKoreksi(EndingBalanceAp $eb, float $saldoAkhirSistem): float
    {
        $totalKoreksi = (float) $eb->koreksiApproved()->sum('nilai_koreksi');

        return max(0, $saldoAkhirSistem + $totalKoreksi);
    }

    /**
     * Buat EB AP baru atau recalculate EB AP DRAFT yang sudah ada untuk vendor+perusahaan+periode.
     * Dipanggil oleh TagihanApObserver dan PembayaranApObserver. LOCKED EB tidak disentuh.
     */
    public function syncEbForVendor(int $vendorId, int $perusahaanId, string $periodeAwal, string $periodeAkhir, int $userId): void
    {
        $from = Carbon::parse($periodeAwal)->startOfDay();
        $to   = Carbon::parse($periodeAkhir)->endOfDay();

        [$saldoAwal, $tagihanMasuk, $pembayaran] = $this->computeComponents($vendorId, $perusahaanId, $from, $to);
        $saldoAkhirSistem = max(0, $saldoAwal + $tagihanMasuk - $pembayaran);

        $eb = EndingBalanceAp::where('vendor_ap_id', $vendorId)
            ->where('perusahaan_id', $perusahaanId)
            ->where('periode_awal', $periodeAwal)
            ->where('periode_akhir', $periodeAkhir)
            ->first();

        if (!$eb) {
            EndingBalanceAp::create([
                'vendor_ap_id'       => $vendorId,
                'perusahaan_id'      => $perusahaanId,
                'periode_awal'       => $periodeAwal,
                'periode_akhir'      => $periodeAkhir,
                'saldo_awal'         => $saldoAwal,
                'tagihan_masuk'      => $tagihanMasuk,
                'pembayaran'         => $pembayaran,
                'saldo_akhir_sistem' => $saldoAkhirSistem,
                'saldo_akhir_final'  => $saldoAkhirSistem,
                'status'             => 'DRAFT',
                'created_by'         => $userId,
                'updated_by'         => $userId,
            ]);
        } elseif (!$eb->isLocked()) {
            $eb->update([
                'saldo_awal'         => $saldoAwal,
                'tagihan_masuk'      => $tagihanMasuk,
                'pembayaran'         => $pembayaran,
                'saldo_akhir_sistem' => $saldoAkhirSistem,
                'saldo_akhir_final'  => $this->finalWithKoreksi($eb, $saldoAkhirSistem),
                'updated_by'         => $userId,
            ]);
        }

        // Cascade: perbarui EB DRAFT bulan-bulan berikutnya yang saldo_awal-nya bergantung
        // pada saldo bulan ini, agar user tidak perlu klik Hitung Ulang secara manual.
        $this->cascadeSyncForward($vendorId, $perusahaanId, $periodeAkhir, $userId);
    }

    /**
     * Backfill baris tb_ending_balance_ap yang belum pernah tercipta untuk data
     * historis (tagihan/pembayaran yang sudah ada SEBELUM observer TagihanAp/
     * PembayaranAp terpasang, sehingga tidak pernah memicu sync otomatis).
     *
     * Tidak menduplikasi logika kalkulasi — murni menemukan pasangan
     * vendor+perusahaan+bulan yang punya aktivitas, lalu delegasikan penuh ke
     * syncEbForVendor() (create-if-missing / update-if-DRAFT / skip-if-LOCKED).
     */
    public function backfillAll(int $userId): array
    {
        $pairs         = $this->resolveActivePairs();
        $periodsSynced = 0;

        foreach ($pairs as $pair) {
            $months = $this->resolveActiveMonths($pair->vendor_ap_id, $pair->perusahaan_id);

            foreach ($months as [$periodeAwal, $periodeAkhir]) {
                $this->syncEbForVendor($pair->vendor_ap_id, $pair->perusahaan_id, $periodeAwal, $periodeAkhir, $userId);
                $periodsSynced++;
            }
        }

        return [
            'pairs'          => $pairs->count(),
            'periods_synced' => $periodsSynced,
        ];
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Semua pasangan (vendor_ap_id, perusahaan_id) yang punya aktivitas tagihan
     * atau pembayaran — sumber untuk backfillAll().
     */
    private function resolveActivePairs(): \Illuminate\Support\Collection
    {
        $fromTagihan = TagihanAp::query()
            ->select('vendor_ap_id', 'perusahaan_id')
            ->where(fn($q) => $q
                ->where('is_opening_balance', false)
                ->orWhere(fn($q2) => $q2->where('is_opening_balance', true)->where('approval_status', 'APPROVED'))
            )
            ->distinct()
            ->get();

        $fromPembayaran = DB::table('tb_pembayaran_ap_items as pai')
            ->join('tb_tagihan_ap as ta', 'pai.tagihan_ap_id', '=', 'ta.id')
            ->select('pai.vendor_ap_id', 'ta.perusahaan_id')
            ->distinct()
            ->get();

        return $fromTagihan->concat($fromPembayaran)
            ->unique(fn($row) => $row->vendor_ap_id . '-' . $row->perusahaan_id)
            ->values();
    }

    /**
     * Semua bulan (periode_awal, periode_akhir) yang punya aktivitas tagihan
     * atau pembayaran untuk satu pasangan vendor+perusahaan, urut ascending.
     */
    private function resolveActiveMonths(int $vendorId, int $perusahaanId): array
    {
        $tagihanDates = TagihanAp::query()
            ->where('vendor_ap_id', $vendorId)
            ->where('perusahaan_id', $perusahaanId)
            ->where(fn($q) => $q
                ->where('is_opening_balance', false)
                ->orWhere(fn($q2) => $q2->where('is_opening_balance', true)->where('approval_status', 'APPROVED'))
            )
            ->pluck('tanggal_tagihan');

        $pembayaranDates = DB::table('tb_pembayaran_ap_items as pai')
            ->join('tb_pembayaran_ap as pa', 'pai.pembayaran_ap_id', '=', 'pa.id')
            ->join('tb_tagihan_ap as ta', 'pai.tagihan_ap_id', '=', 'ta.id')
            ->where('pai.vendor_ap_id', $vendorId)
            ->where('ta.perusahaan_id', $perusahaanId)
            ->pluck('pa.tanggal_pembayaran');

        return $tagihanDates->concat($pembayaranDates)
            ->map(fn($d) => Carbon::parse($d)->startOfMonth()->toDateString())
            ->unique()
            ->sort()
            ->values()
            ->map(fn($start) => [$start, Carbon::parse($start)->endOfMonth()->toDateString()])
            ->all();
    }

    /**
     * Setelah satu periode di-sync, perbarui EB DRAFT bulan-bulan berikutnya
     * agar saldo_awal mereka ikut diperbarui tanpa perlu Hitung Ulang manual.
     * Berhenti saat tidak ada DRAFT EB di bulan berikutnya, atau maks 6 bulan.
     */
    private function cascadeSyncForward(int $vendorId, int $perusahaanId, string $periodeAkhirCurrent, int $userId, int $depth = 0): void
    {
        if ($depth >= 6) return;

        $nextStart = Carbon::parse($periodeAkhirCurrent)->addDay()->startOfMonth()->toDateString();
        $nextEnd   = Carbon::parse($nextStart)->endOfMonth()->toDateString();

        $nextEb = EndingBalanceAp::where('vendor_ap_id', $vendorId)
            ->where('perusahaan_id', $perusahaanId)
            ->where('periode_awal', $nextStart)
            ->where('status', 'DRAFT')
            ->first();

        if (!$nextEb) return;

        $from = Carbon::parse($nextStart)->startOfDay();
        $to   = Carbon::parse($nextEnd)->endOfDay();
        [$saldoAwal, $tagihanMasuk, $pembayaran] = $this->computeComponents($vendorId, $perusahaanId, $from, $to);
        $saldoAkhirSistem = max(0, $saldoAwal + $tagihanMasuk - $pembayaran);

        $nextEb->update([
            'saldo_awal'         => $saldoAwal,
            'tagihan_masuk'      => $tagihanMasuk,
            'pembayaran'         => $pembayaran,
            'saldo_akhir_sistem' => $saldoAkhirSistem,
            'saldo_akhir_final'  => $this->finalWithKoreksi($nextEb, $saldoAkhirSistem),
            'updated_by'         => $userId,
        ]);

        $this->cascadeSyncForward($vendorId, $perusahaanId, $nextEnd, $userId, $depth + 1);
    }

    /**
     * Compute the three components for a given vendor+perusahaan and period.
     * Uses approved EB snapshot from previous period when available (carry-forward),
     * otherwise falls back to computing from full history.
     */
    private function computeComponents(int $vendorId, int $perusahaanId, Carbon $from, Carbon $to): array
    {
        // 1. Saldo awal: prefer snapshot from previous LOCKED EB
        $prevEb = EndingBalanceAp::where('vendor_ap_id', $vendorId)
            ->where('perusahaan_id', $perusahaanId)
            ->where('status', 'LOCKED')
            ->where('periode_akhir', '<', $from->toDateString())
            ->orderByDesc('periode_akhir')
            ->first();

        if ($prevEb) {
            $saldoAwal = (float) $prevEb->saldo_akhir_final;
        } else {
            // Fallback: sisa hutang belum terbayar dari seluruh tagihan sebelum periode.
            // total_tagihan pada TagihanAp bukan angka kumulatif berjalan (beda dengan
            // Invoice.total_tagihan di AR) — jadi aman langsung dijumlah.
            $saldoAwal = (float) TagihanAp::query()
                ->where('vendor_ap_id', $vendorId)
                ->where('perusahaan_id', $perusahaanId)
                ->where('tanggal_tagihan', '<', $from->toDateString())
                ->where(fn($q) => $q
                    ->where('is_opening_balance', false)
                    ->orWhere(fn($q2) => $q2->where('is_opening_balance', true)->where('approval_status', 'APPROVED'))
                )
                ->selectRaw('COALESCE(SUM(GREATEST(0, total_tagihan - total_pembayaran - total_penyesuaian)), 0) as total')
                ->value('total') ?? 0.0;
        }

        // 2. Tagihan masuk dalam periode
        $tagihanMasuk = (float) TagihanAp::query()
            ->where('vendor_ap_id', $vendorId)
            ->where('perusahaan_id', $perusahaanId)
            ->whereBetween('tanggal_tagihan', [$from->toDateString(), $to->toDateString()])
            ->where(fn($q) => $q
                ->where('is_opening_balance', false)
                ->orWhere(fn($q2) => $q2->where('is_opening_balance', true)->where('approval_status', 'APPROVED'))
            )
            ->sum('total_tagihan');

        // 3. Pembayaran dalam periode (sumber kebenaran: tb_pembayaran_ap_items, karena
        // header voucher tidak lagi mengisi tagihan_ap_id untuk voucher multi-vendor)
        $pembayaran = (float) DB::table('tb_pembayaran_ap_items as pai')
            ->join('tb_pembayaran_ap as pa', 'pai.pembayaran_ap_id', '=', 'pa.id')
            ->join('tb_tagihan_ap as ta', 'pai.tagihan_ap_id', '=', 'ta.id')
            ->where('pai.vendor_ap_id', $vendorId)
            ->where('ta.perusahaan_id', $perusahaanId)
            ->whereBetween('pa.tanggal_pembayaran', [$from->toDateString(), $to->toDateString()])
            ->sum('pai.jumlah_dialokasikan');

        return [$saldoAwal, $tagihanMasuk, $pembayaran];
    }
}
