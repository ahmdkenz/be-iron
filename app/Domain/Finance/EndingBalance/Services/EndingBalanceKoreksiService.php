<?php

namespace App\Domain\Finance\EndingBalance\Services;

use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Models\EndingBalance;
use App\Models\EndingBalanceKoreksi;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class EndingBalanceKoreksiService
{
    public function __construct(
        private readonly EndingBalanceService $ebService,
        private readonly InvoiceService $invoiceService,
    ) {}

    /**
     * Submit a new correction request.
     *
     * - EB LOCKED  : koreksi (dengan/tanpa invoice) butuh approval SPV → Manager.
     * - EB DRAFT   : hanya penyesuaian per-invoice (invoice_id terisi) yang diizinkan,
     *                langsung di-approve & diterapkan ke invoice.
     */
    public function submit(EndingBalance $eb, array $data, int $userId): EndingBalanceKoreksi
    {
        $hasInvoice = !empty($data['invoice_id']);

        if ($eb->isLocked()) {
            abort_if($eb->hasActiveKoreksi(), 422, 'Masih ada koreksi yang sedang dalam proses persetujuan.');
        } else {
            abort_unless(
                $hasInvoice,
                422,
                'Koreksi nominal hanya untuk ending balance terkunci. Untuk EB draft, tautkan penyesuaian ke invoice tertentu.'
            );
        }

        return DB::transaction(function () use ($eb, $data, $userId) {
            $immediate = !$eb->isLocked(); // DRAFT → langsung berlaku

            $koreksi = EndingBalanceKoreksi::create([
                'ending_balance_id'   => $eb->id,
                'klien_ar_id'         => $eb->klien_ar_id,
                'invoice_id'          => $data['invoice_id'] ?? null,
                'nilai_koreksi'       => $data['nilai_koreksi'],
                'alasan_koreksi'      => $data['alasan_koreksi'],
                'dokumen_url'         => $data['dokumen_url'] ?? null,
                'status'              => $immediate ? 'APPROVED' : 'PENDING_SPV',
                'submitted_by'        => $userId,
                'submitted_at'        => now(),
                'spv_note'            => $immediate ? 'Otomatis (EB draft)' : null,
                'spv_actioned_at'     => $immediate ? now() : null,
                'manager_note'        => $immediate ? 'Otomatis (EB draft)' : null,
                'manager_actioned_at' => $immediate ? now() : null,
                'updated_by'          => $userId,
            ]);

            if ($immediate) {
                // Terapkan ke invoice; InvoiceObserver memicu syncEbForKlien (DRAFT)
                // yang sudah memperhitungkan koreksi approved via finalWithKoreksi().
                $this->applyPenyesuaian($koreksi);
            }

            return $koreksi->fresh();
        });
    }

    /**
     * Terapkan penyesuaian ke invoice yang ditautkan: tambah total_penyesuaian
     * (mengurangi outstanding) lalu recalculate invoice. Hanya untuk koreksi
     * yang menautkan invoice dan bernilai negatif (mengurangi saldo).
     */
    private function applyPenyesuaian(EndingBalanceKoreksi $koreksi): void
    {
        if (!$koreksi->invoice_id || (float) $koreksi->nilai_koreksi >= 0) {
            return;
        }

        $invoice = Invoice::find($koreksi->invoice_id);
        if (!$invoice) {
            return;
        }

        $invoice->total_penyesuaian = (float) $invoice->total_penyesuaian + abs((float) $koreksi->nilai_koreksi);
        $invoice->save();

        $this->invoiceService->recalculate($invoice->fresh());
    }

    /**
     * SPV approves the correction → moves to PENDING_MANAGER.
     */
    public function approveSpv(EndingBalanceKoreksi $koreksi, ?string $note, int $spvId): EndingBalanceKoreksi
    {
        abort_unless($koreksi->isPendingSpv(), 422, 'Koreksi tidak dalam status PENDING_SPV.');

        $koreksi->update([
            'status'         => 'PENDING_MANAGER',
            'spv_id'         => $spvId,
            'spv_note'       => $note,
            'spv_actioned_at'=> now(),
            'updated_by'     => $spvId,
        ]);

        return $koreksi->fresh();
    }

    /**
     * SPV rejects the correction.
     */
    public function rejectSpv(EndingBalanceKoreksi $koreksi, string $note, int $spvId): EndingBalanceKoreksi
    {
        abort_unless($koreksi->isPendingSpv(), 422, 'Koreksi tidak dalam status PENDING_SPV.');

        $koreksi->update([
            'status'         => 'REJECTED',
            'spv_id'         => $spvId,
            'spv_note'       => $note,
            'spv_actioned_at'=> now(),
            'updated_by'     => $spvId,
        ]);

        return $koreksi->fresh();
    }

    /**
     * Manager approves the correction → APPROVED, triggers recompute of saldo_akhir_final.
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

            // Penyesuaian per-invoice: kurangi outstanding invoice yang ditautkan.
            $this->applyPenyesuaian($koreksi->fresh());

            // Saldo akhir final = saldo sistem + Σ koreksi approved (termasuk yang baru ini).
            $this->ebService->recomputeFinal($koreksi->endingBalance);

            return $koreksi->fresh();
        });
    }

    /**
     * Manager rejects the correction.
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

        return $koreksi->fresh();
    }

    /**
     * List corrections pending action for the currently authenticated user.
     * SPV sees PENDING_SPV, Manager sees PENDING_MANAGER.
     */
    public function pendingForUser(string $role): \Illuminate\Database\Eloquent\Collection
    {
        $status = match(strtoupper($role)) {
            'SUPERVISOR' => 'PENDING_SPV',
            'MANAGER'    => 'PENDING_MANAGER',
            default      => null,
        };

        if (!$status) {
            return EndingBalanceKoreksi::newModelInstance()->newCollection();
        }

        return EndingBalanceKoreksi::with(['endingBalance.klienAr', 'submittedBy', 'invoice'])
            ->where('status', $status)
            ->orderBy('submitted_at')
            ->get();
    }

    /**
     * List all approved corrections, newest first.
     */
    public function approved(): \Illuminate\Database\Eloquent\Collection
    {
        return EndingBalanceKoreksi::with(['endingBalance.klienAr', 'submittedBy', 'spv', 'manager', 'invoice'])
            ->where('status', 'APPROVED')
            ->orderByDesc('manager_actioned_at')
            ->get();
    }
}
