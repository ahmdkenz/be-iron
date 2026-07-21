<?php

namespace App\Domain\Finance\EndingBalanceAp\Services;

use App\Domain\Finance\TagihanAp\Services\TagihanApService;
use App\Models\EndingBalanceAp;
use App\Models\EndingBalanceApKoreksi;
use App\Models\EndingBalanceApKoreksiItem;
use App\Models\TagihanAp;
use App\Models\TagihanApItem;
use Illuminate\Support\Facades\DB;

class EndingBalanceApKoreksiService
{
    public function __construct(
        private readonly EndingBalanceApService $ebService,
        private readonly TagihanApService $tagihanApService,
    ) {}

    /**
     * Submit a new correction request.
     *
     * Tipe koreksi:
     *  - KOREKSI_SALDO : penyesuaian nominal umum (hanya LOCKED EB, tanpa tagihan)
     *  - CREDIT_NOTE   : pengurangan hutang per-tagihan → nilai_koreksi < 0
     *  - DEBIT_NOTE    : penambahan hutang per-tagihan → nilai_koreksi > 0
     *
     * CREDIT_NOTE/DEBIT_NOTE boleh opsional menyertakan koreksi qty/harga per item tagihan,
     * yang membuat nilai_koreksi dihitung otomatis dari SUM(selisih) item.
     *
     * Semua tipe langsung masuk status PENDING, diproses satu tahap oleh
     * Manager/Supervisor/Admin.
     */
    public function submit(EndingBalanceAp $eb, array $data, int $userId): EndingBalanceApKoreksi
    {
        $tipe       = $data['tipe'] ?? 'KOREKSI_SALDO';
        $hasTagihan = !empty($data['tagihan_ap_id']);

        if ($tipe === 'KOREKSI_SALDO') {
            abort_unless($eb->isLocked(), 422, 'Koreksi saldo hanya untuk ending balance terkunci.');
            abort_unless(!$hasTagihan, 422, 'Koreksi saldo tidak boleh tautkan ke tagihan. Gunakan tipe Credit Note atau Debit Note.');
        } else {
            abort_unless($hasTagihan, 422, 'Tipe ' . $tipe . ' harus tautkan ke tagihan tertentu.');
        }

        abort_if($eb->isLocked() && $eb->hasActiveKoreksi(), 422, 'Masih ada koreksi yang sedang dalam proses persetujuan.');

        return DB::transaction(function () use ($eb, $data, $userId, $tipe) {
            $nilaiKoreksi = $this->resolveNilaiKoreksi($data);
            $noDokumen    = in_array($tipe, ['CREDIT_NOTE', 'DEBIT_NOTE'])
                ? $this->generateNoDokumen($tipe)
                : null;

            $koreksi = EndingBalanceApKoreksi::create([
                'ending_balance_ap_id' => $eb->id,
                'vendor_ap_id'         => $eb->vendor_ap_id,
                'tagihan_ap_id'        => $data['tagihan_ap_id'] ?? null,
                'tipe'                 => $tipe,
                'no_dokumen'           => $noDokumen,
                'nilai_koreksi'        => $nilaiKoreksi,
                'alasan_koreksi'       => $data['alasan_koreksi'],
                'dokumen_url'          => $data['dokumen_url'] ?? null,
                'status'               => 'PENDING',
                'submitted_by'         => $userId,
                'submitted_at'         => now(),
                'updated_by'           => $userId,
            ]);

            if (!empty($data['items']) && in_array($tipe, ['CREDIT_NOTE', 'DEBIT_NOTE'])) {
                $this->createKoreksiItems($koreksi, $data['items']);
            }

            return $koreksi->fresh(['items']);
        });
    }

    /**
     * Manager/Supervisor/Admin menyetujui koreksi → APPROVED,
     * memicu recompute saldo_akhir_final dan penyesuaian tagihan terkait.
     */
    public function approve(EndingBalanceApKoreksi $koreksi, ?string $note, int $approverId): EndingBalanceApKoreksi
    {
        abort_unless($koreksi->isPending(), 422, 'Koreksi tidak dalam status PENDING.');

        return DB::transaction(function () use ($koreksi, $note, $approverId) {
            $koreksi->update([
                'status'        => 'APPROVED',
                'approved_by'   => $approverId,
                'approved_note' => $note,
                'approved_at'   => now(),
                'updated_by'    => $approverId,
            ]);

            $this->applyPenyesuaian($koreksi->fresh(['items']));

            $this->ebService->recomputeFinal($koreksi->endingBalanceAp);

            return $koreksi->fresh();
        });
    }

    /**
     * Manager/Supervisor/Admin menolak koreksi.
     */
    public function reject(EndingBalanceApKoreksi $koreksi, string $note, int $approverId): EndingBalanceApKoreksi
    {
        abort_unless($koreksi->isPending(), 422, 'Koreksi tidak dalam status PENDING.');

        $koreksi->update([
            'status'        => 'REJECTED',
            'approved_by'   => $approverId,
            'approved_note' => $note,
            'approved_at'   => now(),
            'updated_by'    => $approverId,
        ]);

        return $koreksi->fresh();
    }

    /**
     * List corrections pending action for the currently authenticated user.
     * Manager, Supervisor, maupun Admin bisa memproses. Role AP tidak melihat inbox ini.
     */
    public function pendingForUser(string $role): \Illuminate\Database\Eloquent\Collection
    {
        $canApprove = in_array(strtoupper($role), ['MANAGER', 'SUPERVISOR', 'ADMIN'], true);

        if (!$canApprove) {
            return EndingBalanceApKoreksi::newModelInstance()->newCollection();
        }

        return EndingBalanceApKoreksi::with(['endingBalanceAp.vendorAp', 'vendorAp', 'submittedBy', 'tagihanAp', 'items'])
            ->where('status', 'PENDING')
            ->orderBy('submitted_at')
            ->get();
    }

    /**
     * List all approved corrections, newest first (paginated).
     */
    public function approved(int $perPage = 20, int $page = 1): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return EndingBalanceApKoreksi::with(['endingBalanceAp.vendorAp', 'vendorAp', 'submittedBy', 'approvedBy', 'tagihanAp', 'items'])
            ->whereIn('status', ['PENDING', 'APPROVED'])
            ->orderByDesc(DB::raw('COALESCE(approved_at, submitted_at)'))
            ->paginate($perPage, ['*'], 'page', $page);
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    /**
     * Hitung nilai_koreksi dari input:
     * - CREDIT_NOTE/DEBIT_NOTE dengan items: SUM(selisih) dari semua items
     * - Lainnya: nilai dari field nilai_koreksi
     */
    private function resolveNilaiKoreksi(array $data): float
    {
        $tipe = $data['tipe'] ?? 'KOREKSI_SALDO';

        if (!empty($data['items']) && in_array($tipe, ['CREDIT_NOTE', 'DEBIT_NOTE'])) {
            $total = 0.0;
            foreach ($data['items'] as $item) {
                $tagihanItem  = TagihanApItem::findOrFail($item['tagihan_ap_item_id']);
                $subtotalLama = (float) $tagihanItem->qty * (float) $tagihanItem->harga_satuan;
                $subtotalBaru = (float) $item['qty_baru'] * (float) $item['harga_satuan_baru'];
                $total       += $subtotalBaru - $subtotalLama;
            }
            return round($total, 2);
        }

        return (float) $data['nilai_koreksi'];
    }

    /**
     * Simpan detail item koreksi (opsional untuk CREDIT_NOTE/DEBIT_NOTE).
     */
    private function createKoreksiItems(EndingBalanceApKoreksi $koreksi, array $items): void
    {
        foreach ($items as $item) {
            $tagihanItem  = TagihanApItem::findOrFail($item['tagihan_ap_item_id']);
            $subtotalLama = round((float) $tagihanItem->qty * (float) $tagihanItem->harga_satuan, 2);
            $subtotalBaru = round((float) $item['qty_baru'] * (float) $item['harga_satuan_baru'], 2);

            EndingBalanceApKoreksiItem::create([
                'ending_balance_ap_koreksi_id' => $koreksi->id,
                'tagihan_ap_item_id'           => $tagihanItem->id,
                'nama_barang'                  => $tagihanItem->nama_barang,
                'qty_lama'                     => (float) $tagihanItem->qty,
                'harga_satuan_lama'            => (float) $tagihanItem->harga_satuan,
                'subtotal_lama'                => $subtotalLama,
                'qty_baru'                     => (float) $item['qty_baru'],
                'harga_satuan_baru'            => (float) $item['harga_satuan_baru'],
                'subtotal_baru'                => $subtotalBaru,
                'selisih'                      => $subtotalBaru - $subtotalLama,
            ]);
        }
    }

    /**
     * Terapkan efek koreksi ke tagihan saat disetujui.
     *
     * - CREDIT_NOTE   : tambah total_penyesuaian → sisa hutang berkurang
     * - DEBIT_NOTE    : kurangi total_penyesuaian → sisa hutang bertambah
     * - KOREKSI_SALDO : tidak menyentuh tagihan
     */
    private function applyPenyesuaian(EndingBalanceApKoreksi $koreksi): void
    {
        if (!$koreksi->tagihan_ap_id) {
            return;
        }

        $tagihan = TagihanAp::find($koreksi->tagihan_ap_id);
        if (!$tagihan) {
            return;
        }

        $delta = match ($koreksi->tipe) {
            'CREDIT_NOTE' =>  abs((float) $koreksi->nilai_koreksi),
            'DEBIT_NOTE'  => -abs((float) $koreksi->nilai_koreksi),
            default       => null,
        };

        if ($delta === null) {
            return;
        }

        $tagihan->total_penyesuaian = round((float) $tagihan->total_penyesuaian + $delta, 2);
        $tagihan->save();

        $this->tagihanApService->recalculate($tagihan->fresh());
    }

    /**
     * Generate nomor dokumen untuk Credit Note dan Debit Note.
     * Format: CN-AP-YYYYMM-XXXX / DN-AP-YYYYMM-XXXX (sequential per bulan)
     */
    private function generateNoDokumen(string $tipe): string
    {
        $prefix    = $tipe === 'CREDIT_NOTE' ? 'CN-AP' : 'DN-AP';
        $yearMonth = now()->format('Ym');

        $last = EndingBalanceApKoreksi::where('no_dokumen', 'LIKE', $prefix . '-' . $yearMonth . '-%')
            ->max('no_dokumen');

        $seq = 1;
        if ($last) {
            $seq = (int) substr($last, strrpos($last, '-') + 1) + 1;
        }

        return $prefix . '-' . $yearMonth . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
