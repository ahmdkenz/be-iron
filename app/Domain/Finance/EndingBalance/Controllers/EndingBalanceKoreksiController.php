<?php

namespace App\Domain\Finance\EndingBalance\Controllers;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceKoreksiService;
use App\Http\Controllers\Controller;
use App\Models\EndingBalance;
use App\Models\EndingBalanceKoreksi;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EndingBalanceKoreksiController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly EndingBalanceKoreksiService $service) {}

    /**
     * Submit koreksi baru untuk satu ending balance.
     */
    public function store(Request $request, int $ebId): JsonResponse
    {
        $this->authorizeOperate();

        $eb   = EndingBalance::findOrFail($ebId);
        $data = $request->validate([
            'nilai_koreksi'  => ['required', 'numeric', 'not_in:0'],
            'alasan_koreksi' => ['required', 'string', 'max:1000'],
            'dokumen_url'    => ['nullable', 'string', 'max:500'],
        ]);

        $koreksi = $this->service->submit($eb, $data, auth()->id());

        return $this->createdResponse(
            $this->formatKoreksi($koreksi),
            'Koreksi berhasil diajukan dan menunggu persetujuan SPV.'
        );
    }

    /**
     * SPV approve.
     */
    public function approveSpv(Request $request, int $id): JsonResponse
    {
        $this->authorizeSpv();

        $data    = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $koreksi = EndingBalanceKoreksi::with('endingBalance')->findOrFail($id);
        $updated = $this->service->approveSpv($koreksi, $data['note'] ?? null, auth()->id());

        return $this->successResponse(
            $this->formatKoreksi($updated),
            'Koreksi disetujui oleh SPV, menunggu persetujuan Manager.'
        );
    }

    /**
     * SPV reject.
     */
    public function rejectSpv(Request $request, int $id): JsonResponse
    {
        $this->authorizeSpv();

        $data    = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $koreksi = EndingBalanceKoreksi::findOrFail($id);
        $updated = $this->service->rejectSpv($koreksi, $data['note'], auth()->id());

        return $this->successResponse(
            $this->formatKoreksi($updated),
            'Koreksi ditolak oleh SPV.'
        );
    }

    /**
     * Manager approve.
     */
    public function approveManager(Request $request, int $id): JsonResponse
    {
        $this->authorizeManager();

        $data    = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $koreksi = EndingBalanceKoreksi::with('endingBalance')->findOrFail($id);
        $updated = $this->service->approveManager($koreksi, $data['note'] ?? null, auth()->id());

        return $this->successResponse(
            $this->formatKoreksi($updated),
            'Koreksi disetujui oleh Manager. Saldo akhir final telah diperbarui.'
        );
    }

    /**
     * Manager reject.
     */
    public function rejectManager(Request $request, int $id): JsonResponse
    {
        $this->authorizeManager();

        $data    = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $koreksi = EndingBalanceKoreksi::findOrFail($id);
        $updated = $this->service->rejectManager($koreksi, $data['note'], auth()->id());

        return $this->successResponse(
            $this->formatKoreksi($updated),
            'Koreksi ditolak oleh Manager.'
        );
    }

    /**
     * Inbox: koreksi yang menunggu aksi dari user yang sedang login.
     */
    public function pending(): JsonResponse
    {
        $user  = auth()->user();
        $roles = $user->getRoleNames()->map(fn($r) => strtoupper($r));

        $role = $roles->first(fn($r) => in_array($r, ['SUPERVISOR', 'MANAGER']));

        $list = $this->service->pendingForUser($role ?? '');

        return $this->successResponse(
            $list->map(fn($k) => $this->formatKoreksi($k))->values()->all()
        );
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function formatKoreksi(EndingBalanceKoreksi $k): array
    {
        return [
            'id'                  => $k->id,
            'ending_balance_id'   => $k->ending_balance_id,
            'klien_ar_id'         => $k->klien_ar_id,
            'nama_klien'          => $k->klienAr?->nama_klien ?? $k->endingBalance?->klienAr?->nama_klien,
            'nilai_koreksi'       => (float) $k->nilai_koreksi,
            'alasan_koreksi'      => $k->alasan_koreksi,
            'dokumen_url'         => $k->dokumen_url,
            'status'              => $k->status,
            'submitted_by'        => $k->submittedBy?->username,
            'submitted_at'        => $k->submitted_at?->toIso8601String(),
            'spv'                 => $k->spv?->username,
            'spv_note'            => $k->spv_note,
            'spv_actioned_at'     => $k->spv_actioned_at?->toIso8601String(),
            'manager'             => $k->manager?->username,
            'manager_note'        => $k->manager_note,
            'manager_actioned_at' => $k->manager_actioned_at?->toIso8601String(),
        ];
    }

    private function authorizeOperate(): void
    {
        abort_if(
            !RoleHelper::canOperateEndingBalance(auth()->user()),
            403, 'Tidak memiliki akses untuk mengajukan koreksi.'
        );
    }

    private function authorizeSpv(): void
    {
        abort_if(
            !RoleHelper::canApproveEndingBalanceSpv(auth()->user()),
            403, 'Hanya SPV yang dapat melakukan aksi ini.'
        );
    }

    private function authorizeManager(): void
    {
        abort_if(
            !RoleHelper::canApproveEndingBalanceManager(auth()->user()),
            403, 'Hanya Manager yang dapat melakukan aksi ini.'
        );
    }
}
