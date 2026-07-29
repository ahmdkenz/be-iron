<?php

namespace App\Domain\Finance\EndingBalance\Services;

use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Notification\Services\FinanceNotificationService;
use App\Models\EndingBalance;
use App\Models\EndingBalanceKoreksi;
use App\Models\EndingBalanceKoreksiItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;

class EndingBalanceKoreksiService
{
    public function __construct(
        private readonly EndingBalanceService $ebService,
        private readonly InvoiceService $invoiceService,
        private readonly FinanceNotificationService $financeNotificationService,
    ) {}

    /**
     * Submit a new correction request.
     *
     * Tipe koreksi:
     *  - KOREKSI_SALDO : penyesuaian nominal umum (hanya LOCKED EB, tanpa invoice)
     *  - CREDIT_NOTE   : pengurangan tagihan per-invoice → nilai_koreksi < 0
     *  - DEBIT_NOTE    : penambahan tagihan per-invoice → nilai_koreksi > 0
     *
     * CREDIT_NOTE/DEBIT_NOTE boleh opsional menyertakan koreksi qty/harga per item invoice,
     * yang membuat nilai_koreksi dihitung otomatis dari SUM(selisih) item (lihat resolveNilaiKoreksi()).
     *
     * Semua tipe langsung masuk pending final approval (PENDING_MANAGER),
     * yang dapat diproses oleh Manager atau Supervisor (satu tahap).
     */
    public function submit(EndingBalance $eb, array $data, int $userId): EndingBalanceKoreksi
    {
        $tipe       = $data['tipe'] ?? 'KOREKSI_SALDO';
        $hasInvoice = !empty($data['invoice_id']);

        if ($tipe === 'KOREKSI_SALDO') {
            abort_unless($eb->isLocked(), 422, 'Koreksi saldo hanya untuk ending balance terkunci.');
            abort_unless(!$hasInvoice, 422, 'Koreksi saldo tidak boleh tautkan ke invoice. Gunakan tipe Credit Note atau Debit Note.');
        } else {
            abort_unless($hasInvoice, 422, 'Tipe ' . $tipe . ' harus tautkan ke invoice tertentu.');
        }

        abort_if($eb->isLocked() && $eb->hasActiveKoreksi(), 422, 'Masih ada koreksi yang sedang dalam proses persetujuan.');

        return DB::transaction(function () use ($eb, $data, $userId, $tipe) {
            $nilaiKoreksi = $this->resolveNilaiKoreksi($data);
            $noDokumen    = in_array($tipe, ['CREDIT_NOTE', 'DEBIT_NOTE'])
                ? $this->generateNoDokumen($tipe)
                : null;

            $koreksi = EndingBalanceKoreksi::create([
                'ending_balance_id' => $eb->id,
                'klien_ar_id'       => $eb->klien_ar_id,
                'invoice_id'        => $data['invoice_id'] ?? null,
                'tipe'              => $tipe,
                'no_dokumen'        => $noDokumen,
                'nilai_koreksi'     => $nilaiKoreksi,
                'alasan_koreksi'    => $data['alasan_koreksi'],
                'dokumen_url'       => $data['dokumen_url'] ?? null,
                'status'            => 'PENDING_MANAGER',
                'submitted_by'      => $userId,
                'submitted_at'      => now(),
                'updated_by'        => $userId,
            ]);

            if (!empty($data['items']) && in_array($tipe, ['CREDIT_NOTE', 'DEBIT_NOTE'])) {
                $this->createKoreksiItems($koreksi, $data['items']);
            }

            $submitted = $koreksi->fresh(['items']);
            $this->financeNotificationService->ebKoreksiArSubmitted($submitted);

            return $submitted;
        });
    }

    /**
     * SPV approves the correction → moves to PENDING_MANAGER.
     */
    public function approveSpv(EndingBalanceKoreksi $koreksi, ?string $note, int $spvId): EndingBalanceKoreksi
    {
        abort_unless($koreksi->isPendingSpv(), 422, 'Koreksi tidak dalam status PENDING_SPV.');

        $koreksi->update([
            'status'          => 'PENDING_MANAGER',
            'spv_id'          => $spvId,
            'spv_note'        => $note,
            'spv_actioned_at' => now(),
            'updated_by'      => $spvId,
        ]);

        $updated = $koreksi->fresh();
        $this->financeNotificationService->ebKoreksiArNeedsManager($updated);

        return $updated;
    }

    /**
     * SPV rejects the correction.
     */
    public function rejectSpv(EndingBalanceKoreksi $koreksi, string $note, int $spvId): EndingBalanceKoreksi
    {
        abort_unless($koreksi->isPendingSpv(), 422, 'Koreksi tidak dalam status PENDING_SPV.');

        $koreksi->update([
            'status'          => 'REJECTED',
            'spv_id'          => $spvId,
            'spv_note'        => $note,
            'spv_actioned_at' => now(),
            'updated_by'      => $spvId,
        ]);

        $updated = $koreksi->fresh();
        $this->financeNotificationService->ebKoreksiArRejected($updated, $note, 'spv');

        return $updated;
    }

    /**
     * Approver (Manager/Supervisor/Admin) menyetujui koreksi → APPROVED,
     * memicu recompute saldo_akhir_final. Data approver disimpan di kolom manager_* existing.
     */
    public function approveManager(EndingBalanceKoreksi $koreksi, ?string $note, int $managerId): EndingBalanceKoreksi
    {
        abort_unless($koreksi->isPendingManager(), 422, 'Koreksi tidak dalam status PENDING_MANAGER.');

        return DB::transaction(function () use ($koreksi, $note, $managerId) {
            $koreksi->update([
                'status'              => 'APPROVED',
                'manager_id'          => $managerId,
                'manager_note'        => $note,
                'manager_actioned_at' => now(),
                'updated_by'          => $managerId,
            ]);

            $this->applyPenyesuaian($koreksi->fresh(['items']));

            $this->ebService->recomputeFinal($koreksi->endingBalance);

            $approved = $koreksi->fresh();
            $this->financeNotificationService->ebKoreksiArApproved($approved);

            return $approved;
        });
    }

    /**
     * Approver (Manager/Supervisor/Admin) menolak koreksi.
     */
    public function rejectManager(EndingBalanceKoreksi $koreksi, string $note, int $managerId): EndingBalanceKoreksi
    {
        abort_unless($koreksi->isPendingManager(), 422, 'Koreksi tidak dalam status PENDING_MANAGER.');

        $koreksi->update([
            'status'              => 'REJECTED',
            'manager_id'          => $managerId,
            'manager_note'        => $note,
            'manager_actioned_at' => now(),
            'updated_by'          => $managerId,
        ]);

        $rejected = $koreksi->fresh();
        $this->financeNotificationService->ebKoreksiArRejected($rejected, $note, 'manager');

        return $rejected;
    }

    /**
     * List corrections pending action for the currently authenticated user.
     *
     * Approval EB kini satu tahap: koreksi menunggu di status PENDING_MANAGER dan
     * bisa diproses oleh Manager, Supervisor, maupun Admin. AR tidak melihat inbox ini.
     */
    public function pendingForUser(string $role): \Illuminate\Database\Eloquent\Collection
    {
        $canApprove = in_array(strtoupper($role), ['MANAGER', 'SUPERVISOR', 'ADMIN'], true);

        if (!$canApprove) {
            return EndingBalanceKoreksi::newModelInstance()->newCollection();
        }

        return EndingBalanceKoreksi::with(['endingBalance.klienAr.resto', 'klienAr.resto', 'submittedBy', 'invoice', 'items'])
            ->where('status', 'PENDING_MANAGER')
            ->orderBy('submitted_at')
            ->get();
    }

    /**
     * List all approved corrections, newest first (paginated).
     */
    public function approved(?string $segment = null, int $perPage = 20, int $page = 1): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $segmentTypes = match ($segment) {
            'B2B'   => ['PT'],
            'B2C'   => ['RESTO'],
            default => null,
        };

        return EndingBalanceKoreksi::with(['endingBalance.klienAr', 'submittedBy', 'spv', 'manager', 'invoice', 'items'])
            ->whereIn('status', ['PENDING_MANAGER', 'APPROVED'])
            ->when($segmentTypes, fn($q) => $q->whereHas('klienAr', fn($q2) => $q2->whereIn('tipe_klien', $segmentTypes)))
            ->orderByDesc(DB::raw('COALESCE(manager_actioned_at, spv_actioned_at)'))
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
                $invoiceItem  = InvoiceItem::findOrFail($item['invoice_item_id']);
                $subtotalLama = (float) $invoiceItem->qty * (float) $invoiceItem->harga_satuan;
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
    private function createKoreksiItems(EndingBalanceKoreksi $koreksi, array $items): void
    {
        foreach ($items as $item) {
            $invoiceItem  = InvoiceItem::findOrFail($item['invoice_item_id']);
            $subtotalLama = round((float) $invoiceItem->qty * (float) $invoiceItem->harga_satuan, 2);
            $subtotalBaru = round((float) $item['qty_baru'] * (float) $item['harga_satuan_baru'], 2);

            EndingBalanceKoreksiItem::create([
                'ending_balance_koreksi_id' => $koreksi->id,
                'invoice_item_id'           => $invoiceItem->id,
                'nama_barang'               => $invoiceItem->nama_barang,
                'qty_lama'                  => (float) $invoiceItem->qty,
                'harga_satuan_lama'         => (float) $invoiceItem->harga_satuan,
                'subtotal_lama'             => $subtotalLama,
                'qty_baru'                  => (float) $item['qty_baru'],
                'harga_satuan_baru'         => (float) $item['harga_satuan_baru'],
                'subtotal_baru'             => $subtotalBaru,
                'selisih'                   => $subtotalBaru - $subtotalLama,
            ]);
        }
    }

    /**
     * Terapkan efek koreksi ke invoice saat disetujui Manager.
     *
     * - CREDIT_NOTE   : tambah total_penyesuaian → outstanding berkurang
     * - DEBIT_NOTE    : kurangi total_penyesuaian → outstanding bertambah (bisa negatif)
     * - KOREKSI_SALDO : tidak menyentuh invoice
     */
    private function applyPenyesuaian(EndingBalanceKoreksi $koreksi): void
    {
        if (!$koreksi->invoice_id) {
            return;
        }

        $invoice = Invoice::find($koreksi->invoice_id);
        if (!$invoice) {
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

        $invoice->total_penyesuaian = round((float) $invoice->total_penyesuaian + $delta, 2);
        $invoice->save();

        $this->invoiceService->recalculate($invoice->fresh());
    }

    /**
     * Generate nomor dokumen untuk Credit Note dan Debit Note.
     * Format: CN-YYYYMM-XXXX / DN-YYYYMM-XXXX (sequential per bulan)
     */
    private function generateNoDokumen(string $tipe): string
    {
        $prefix    = $tipe === 'CREDIT_NOTE' ? 'CN' : 'DN';
        $yearMonth = now()->format('Ym');

        $last = EndingBalanceKoreksi::where('no_dokumen', 'LIKE', $prefix . '-' . $yearMonth . '-%')
            ->max('no_dokumen');

        $seq = 1;
        if ($last) {
            $seq = (int) substr($last, strrpos($last, '-') + 1) + 1;
        }

        return $prefix . '-' . $yearMonth . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
