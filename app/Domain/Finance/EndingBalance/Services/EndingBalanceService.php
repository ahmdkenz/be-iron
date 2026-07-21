<?php

namespace App\Domain\Finance\EndingBalance\Services;

use App\Models\EndingBalance;
use App\Models\Invoice;
use App\Models\KlienAr;
use App\Models\PembayaranAr;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EndingBalanceService
{
    /**
     * Apakah tanggal tertentu untuk klien berada dalam periode Ending Balance
     * yang berstatus LOCKED. Dipakai sebagai guard sebelum mengizinkan
     * edit/hapus Invoice (termasuk Opening Balance).
     */
    public function isLockedForPeriod(int $klienArId, string $tanggal): bool
    {
        return EndingBalance::where('klien_ar_id', $klienArId)
            ->where('status', 'LOCKED')
            ->where('periode_awal', '<=', $tanggal)
            ->where('periode_akhir', '>=', $tanggal)
            ->exists();
    }

    /**
     * Paginated list of ending balances with filters.
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        return EndingBalance::with(['klienAr.perusahaan', 'klienAr.resto', 'createdBy'])
            ->when($filters['klien_ar_id'] ?? null, fn($q, $v) => $q->where('klien_ar_id', $v))
            ->when(isset($filters['klien_ids']), fn($q) => $q->whereIn('klien_ar_id', $filters['klien_ids']))
            ->when($filters['status'] ?? null, fn($q, $v) => $q->where('status', $v))
            ->when($filters['periode_awal'] ?? null, fn($q, $v) => $q->where('periode_awal', '>=', $v))
            ->when($filters['periode_akhir'] ?? null, fn($q, $v) => $q->where('periode_akhir', '<=', $v))
            ->when($filters['segment'] ?? null, function ($q, $v) {
                $types = match (strtoupper($v)) {
                    'B2B'   => ['PT'],
                    'B2C'   => ['RESTO'],
                    default => ['PT', 'RESTO'],
                };
                $q->whereHas('klienAr', fn($q) => $q->withTrashed()->whereIn('tipe_klien', $types));
            })
            ->orderByDesc('periode_akhir')
            ->orderBy('klien_ar_id')
            ->paginate($perPage);
    }

    /**
     * Generate ending balance rows (DRAFT) for all relevant clients in a period.
     * Skips clients that already have an EB record for the same period.
     */
    public function generate(string $periodeAwal, string $periodeAkhir, int $createdBy): array
    {
        $from = Carbon::parse($periodeAwal)->startOfDay();
        $to   = Carbon::parse($periodeAkhir)->endOfDay();

        // Collect all klien_ar_id active in or before this period
        $klienIds = $this->resolveActiveKlienIds($from, $to);

        if ($klienIds->isEmpty()) {
            return ['generated' => 0, 'skipped' => 0];
        }

        $generated = 0;
        $skipped   = 0;

        DB::transaction(function () use ($klienIds, $from, $to, $periodeAwal, $periodeAkhir, $createdBy, &$generated, &$skipped) {
            foreach ($klienIds as $klienId) {
                $exists = EndingBalance::where('klien_ar_id', $klienId)
                    ->where('periode_awal', $periodeAwal)
                    ->where('periode_akhir', $periodeAkhir)
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                [$saldoAwal, $invoiceMasuk, $pembayaran] = $this->computeComponents($klienId, $from, $to);
                $saldoAkhirSistem = max(0, $saldoAwal + $invoiceMasuk - $pembayaran);

                EndingBalance::create([
                    'klien_ar_id'        => $klienId,
                    'periode_awal'       => $periodeAwal,
                    'periode_akhir'      => $periodeAkhir,
                    'saldo_awal'         => $saldoAwal,
                    'invoice_masuk'      => $invoiceMasuk,
                    'pembayaran'         => $pembayaran,
                    'saldo_akhir_sistem' => $saldoAkhirSistem,
                    'saldo_akhir_final'  => $saldoAkhirSistem,
                    'status'             => 'DRAFT',
                    'created_by'         => $createdBy,
                    'updated_by'         => $createdBy,
                ]);

                $generated++;
            }
        });

        return ['generated' => $generated, 'skipped' => $skipped];
    }

    /**
     * Lock (tutup periode) a single ending balance row.
     */
    public function lock(EndingBalance $eb, int $userId): EndingBalance
    {
        abort_if($eb->isLocked(), 422, 'Ending balance ini sudah dikunci.');

        $eb->update([
            'status'     => 'LOCKED',
            'locked_by'  => $userId,
            'locked_at'  => now(),
            'updated_by' => $userId,
        ]);

        return $eb->fresh(['klienAr', 'koreksi']);
    }

    /**
     * Unlock (buka kembali) a LOCKED ending balance so its period can be re-imported.
     */
    public function unlock(EndingBalance $eb, int $userId): EndingBalance
    {
        abort_if(!$eb->isLocked(), 422, 'Ending balance ini belum dikunci.');

        $eb->update([
            'status'     => 'DRAFT',
            'locked_by'  => null,
            'locked_at'  => null,
            'updated_by' => $userId,
        ]);

        return $eb->fresh(['klienAr', 'koreksi']);
    }

    /**
     * Recalculate components (saldo_awal, invoice_masuk, pembayaran) for a DRAFT EB.
     * Useful when EB was generated before invoices were entered or approved.
     */
    public function recalculate(EndingBalance $eb, int $userId): EndingBalance
    {
        abort_if($eb->isLocked(), 422, 'Tidak bisa menghitung ulang EB yang sudah dikunci.');

        $from = Carbon::parse($eb->periode_awal)->startOfDay();
        $to   = Carbon::parse($eb->periode_akhir)->endOfDay();

        [$saldoAwal, $invoiceMasuk, $pembayaran] = $this->computeComponents($eb->klien_ar_id, $from, $to);
        $saldoAkhirSistem = max(0, $saldoAwal + $invoiceMasuk - $pembayaran);

        $eb->update([
            'saldo_awal'         => $saldoAwal,
            'invoice_masuk'      => $invoiceMasuk,
            'pembayaran'         => $pembayaran,
            'saldo_akhir_sistem' => $saldoAkhirSistem,
            'saldo_akhir_final'  => $this->finalWithKoreksi($eb, $saldoAkhirSistem),
            'updated_by'         => $userId,
        ]);

        return $eb->fresh(['klienAr']);
    }

    /**
     * Recompute saldo_akhir_final from approved corrections and persist.
     * Called every time a correction changes to APPROVED.
     */
    public function recomputeFinal(EndingBalance $eb): void
    {
        $eb->update([
            'saldo_akhir_final' => $this->finalWithKoreksi($eb, (float) $eb->saldo_akhir_sistem),
            'updated_by'        => auth()->id(),
        ]);
    }

    /**
     * Saldo akhir final = saldo sistem + total koreksi yang sudah di-approve (floored at 0).
     * Dipakai recalculate/syncEbForKlien (DRAFT) agar koreksi yang sudah disetujui
     * tidak terhapus saat komponen dihitung ulang.
     */
    private function finalWithKoreksi(EndingBalance $eb, float $saldoAkhirSistem): float
    {
        $totalKoreksi = (float) $eb->koreksiApproved()->sum('nilai_koreksi');

        return max(0, $saldoAkhirSistem + $totalKoreksi);
    }

    /**
     * Buat EB baru atau recalculate EB DRAFT yang sudah ada untuk klien + periode.
     * Dipanggil oleh InvoiceObserver dan PembayaranArObserver.
     * LOCKED EB tidak disentuh.
     */
    public function syncEbForKlien(int $klienId, string $periodeAwal, string $periodeAkhir, int $userId): void
    {
        $from = Carbon::parse($periodeAwal)->startOfDay();
        $to   = Carbon::parse($periodeAkhir)->endOfDay();

        [$saldoAwal, $invoiceMasuk, $pembayaran] = $this->computeComponents($klienId, $from, $to);
        $saldoAkhirSistem = max(0, $saldoAwal + $invoiceMasuk - $pembayaran);

        $eb = EndingBalance::where('klien_ar_id', $klienId)
            ->where('periode_awal', $periodeAwal)
            ->where('periode_akhir', $periodeAkhir)
            ->first();

        if (!$eb) {
            EndingBalance::create([
                'klien_ar_id'        => $klienId,
                'periode_awal'       => $periodeAwal,
                'periode_akhir'      => $periodeAkhir,
                'saldo_awal'         => $saldoAwal,
                'invoice_masuk'      => $invoiceMasuk,
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
                'invoice_masuk'      => $invoiceMasuk,
                'pembayaran'         => $pembayaran,
                'saldo_akhir_sistem' => $saldoAkhirSistem,
                'saldo_akhir_final'  => $this->finalWithKoreksi($eb, $saldoAkhirSistem),
                'updated_by'         => $userId,
            ]);
        }

        // Cascade: perbarui EB DRAFT bulan-bulan berikutnya yang saldo_awal-nya bergantung
        // pada saldo bulan ini, agar user tidak perlu klik Hitung Ulang secara manual.
        $this->cascadeSyncForward($klienId, $periodeAkhir, $userId);
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Setelah satu periode di-sync, perbarui EB DRAFT bulan-bulan berikutnya
     * agar saldo_awal mereka ikut diperbarui tanpa perlu Hitung Ulang manual.
     * Berhenti saat tidak ada DRAFT EB di bulan berikutnya, atau maks 6 bulan.
     */
    private function cascadeSyncForward(int $klienId, string $periodeAkhirCurrent, int $userId, int $depth = 0): void
    {
        if ($depth >= 6) return;

        $nextStart = Carbon::parse($periodeAkhirCurrent)->addDay()->startOfMonth()->toDateString();
        $nextEnd   = Carbon::parse($nextStart)->endOfMonth()->toDateString();

        $nextEb = EndingBalance::where('klien_ar_id', $klienId)
            ->where('periode_awal', $nextStart)
            ->where('status', 'DRAFT')
            ->first();

        if (!$nextEb) return;

        $from = Carbon::parse($nextStart)->startOfDay();
        $to   = Carbon::parse($nextEnd)->endOfDay();
        [$saldoAwal, $invoiceMasuk, $pembayaran] = $this->computeComponents($klienId, $from, $to);
        $saldoAkhirSistem = max(0, $saldoAwal + $invoiceMasuk - $pembayaran);

        $nextEb->update([
            'saldo_awal'         => $saldoAwal,
            'invoice_masuk'      => $invoiceMasuk,
            'pembayaran'         => $pembayaran,
            'saldo_akhir_sistem' => $saldoAkhirSistem,
            'saldo_akhir_final'  => $this->finalWithKoreksi($nextEb, $saldoAkhirSistem),
            'updated_by'         => $userId,
        ]);

        $this->cascadeSyncForward($klienId, $nextEnd, $userId, $depth + 1);
    }

    /**
     * Compute the three components for a given klien and period.
     * Uses approved EB snapshot from previous period when available (carry-forward),
     * otherwise falls back to computing from full history.
     */
    private function computeComponents(int $klienId, Carbon $from, Carbon $to): array
    {
        // 1. Saldo awal: prefer snapshot from previous LOCKED EB
        $prevEb = EndingBalance::where('klien_ar_id', $klienId)
            ->where('status', 'LOCKED')
            ->where('periode_akhir', '<', $from->toDateString())
            ->orderByDesc('periode_akhir')
            ->first();

        if ($prevEb) {
            $saldoAwal = (float) $prevEb->saldo_akhir_final;
        } else {
            // Fallback: sisa belum terbayar dari seluruh invoice sebelum periode.
            // Pakai (subtotal - total_pembayaran) bukan total_tagihan, karena
            // total_tagihan bersifat kumulatif berjalan (subtotal + carryover bulan)
            // sehingga menjumlahkannya akan double-count tagihan bulan berjalan.
            $saldoAwal = (float) Invoice::query()
                ->where('klien_ar_id', $klienId)
                ->where('tanggal_invoice', '<', $from->toDateString())
                ->where(fn($q) => $q
                    ->where('is_opening_balance', false)
                    ->orWhere(fn($q2) => $q2->where('is_opening_balance', true)->where('approval_status', 'APPROVED'))
                )
                ->selectRaw('COALESCE(SUM(GREATEST(0, CASE WHEN subtotal = 0 THEN sisa_tagihan ELSE subtotal - total_pembayaran - total_penyesuaian END)), 0) as total')
                ->value('total') ?? 0.0;
        }

        // 2. Invoice masuk dalam periode = tagihan BARU (subtotal), bukan total_tagihan
        // kumulatif. subtotal=0 hanya untuk OB legacy → pakai total_tagihan.
        $invoiceMasuk = (float) Invoice::query()
            ->where('klien_ar_id', $klienId)
            ->whereBetween('tanggal_invoice', [$from->toDateString(), $to->toDateString()])
            ->where(fn($q) => $q
                ->where('is_opening_balance', false)
                ->orWhere(fn($q2) => $q2->where('is_opening_balance', true)->where('approval_status', 'APPROVED'))
            )
            ->selectRaw('COALESCE(SUM(CASE WHEN subtotal = 0 THEN total_tagihan ELSE subtotal END), 0) as total')
            ->value('total') ?? 0.0;

        // 3. Pembayaran dalam periode
        $pembayaran = (float) PembayaranAr::query()
            ->join('tb_invoice', 'tb_pembayaran_ar.invoice_id', '=', 'tb_invoice.id')
            ->where('tb_invoice.klien_ar_id', $klienId)
            ->whereBetween('tb_pembayaran_ar.tanggal_pembayaran', [$from->toDateString(), $to->toDateString()])
            ->sum('tb_pembayaran_ar.jumlah_pembayaran');

        return [$saldoAwal, $invoiceMasuk, $pembayaran];
    }

    /**
     * Collect klien_ar_id that have any invoice or payment activity up to end of period.
     */
    private function resolveActiveKlienIds(Carbon $from, Carbon $to): \Illuminate\Support\Collection
    {
        $fromInvoice = Invoice::query()
            ->select('klien_ar_id')
            ->where('tanggal_invoice', '<=', $to->toDateString())
            ->where(fn($q) => $q
                ->where('is_opening_balance', false)
                ->orWhere(fn($q2) => $q2->where('is_opening_balance', true)->where('approval_status', 'APPROVED'))
            )
            ->pluck('klien_ar_id');

        $fromPembayaran = PembayaranAr::query()
            ->join('tb_invoice', 'tb_pembayaran_ar.invoice_id', '=', 'tb_invoice.id')
            ->where('tb_pembayaran_ar.tanggal_pembayaran', '<=', $to->toDateString())
            ->pluck('tb_invoice.klien_ar_id');

        return $fromInvoice->merge($fromPembayaran)->unique()->values();
    }
}
