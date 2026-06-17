<?php

namespace App\Domain\Finance\EndingBalance\Services;

use App\Models\EndingBalance;
use App\Models\EndingBalanceKoreksi;

class EndingBalanceKoreksiService
{
    public function __construct(private readonly EndingBalanceService $ebService) {}

    /**
     * Submit a new correction request. Only allowed when EB is LOCKED and no active correction exists.
     */
    public function submit(EndingBalance $eb, array $data, int $userId): EndingBalanceKoreksi
    {
        abort_unless($eb->isLocked(), 422, 'Koreksi hanya bisa diajukan pada ending balance yang sudah dikunci.');
        abort_if($eb->hasActiveKoreksi(), 422, 'Masih ada koreksi yang sedang dalam proses persetujuan.');

        return EndingBalanceKoreksi::create([
            'ending_balance_id' => $eb->id,
            'klien_ar_id'       => $eb->klien_ar_id,
            'nilai_koreksi'     => $data['nilai_koreksi'],
            'alasan_koreksi'    => $data['alasan_koreksi'],
            'dokumen_url'       => $data['dokumen_url'] ?? null,
            'status'            => 'PENDING_SPV',
            'submitted_by'      => $userId,
            'submitted_at'      => now(),
            'updated_by'        => $userId,
        ]);
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

        $koreksi->update([
            'status'              => 'APPROVED',
            'manager_id'          => $managerId,
            'manager_note'        => $note,
            'manager_actioned_at' => now(),
            'updated_by'          => $managerId,
        ]);

        $this->ebService->recomputeFinal($koreksi->endingBalance);

        return $koreksi->fresh();
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
            return collect();
        }

        return EndingBalanceKoreksi::with(['endingBalance.klienAr', 'submittedBy'])
            ->where('status', $status)
            ->orderBy('submitted_at')
            ->get();
    }
}
