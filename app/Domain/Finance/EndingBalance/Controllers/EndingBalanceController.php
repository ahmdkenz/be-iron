<?php

namespace App\Domain\Finance\EndingBalance\Controllers;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceService;
use App\Http\Controllers\Controller;
use App\Models\EndingBalance;
use App\Models\Invoice;
use App\Models\KlienAr;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EndingBalanceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly EndingBalanceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView();

        $filters = $request->validate([
            'klien_ar_id'   => ['nullable', 'integer', 'exists:tb_klien_ar,id'],
            'status'        => ['nullable', 'in:DRAFT,LOCKED'],
            'periode_awal'  => ['nullable', 'date'],
            'periode_akhir' => ['nullable', 'date'],
            'per_page'      => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // PIC AR only sees their own assigned clients
        $user = auth()->user();
        if (RoleHelper::isArStaff($user)) {
            $karyawan = $user->karyawan;
            $filters['klien_ids'] = $karyawan
                ? KlienAr::where('karyawan_ar_id', $karyawan->id)->pluck('id')->all()
                : [];
        }

        $list = $this->service->paginate($filters);

        return $this->paginatedResponse(
            $list->through(fn($eb) => $this->formatEb($eb))
        );
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeView();

        $eb = EndingBalance::with(['klienAr.perusahaan', 'koreksi.submittedBy', 'koreksi.spv', 'koreksi.manager', 'lockedBy', 'createdBy'])
            ->findOrFail($id);

        return $this->successResponse($this->formatEb($eb, detailed: true));
    }

    public function lock(Request $request, int $id): JsonResponse
    {
        $this->authorizeOperate();

        $eb      = EndingBalance::findOrFail($id);
        $updated = $this->service->lock($eb, auth()->id());

        return $this->successResponse(
            $this->formatEb($updated),
            'Ending balance berhasil dikunci.'
        );
    }

    public function invoices(int $id): JsonResponse
    {
        $this->authorizeView();

        $eb = EndingBalance::findOrFail($id);

        $invoices = Invoice::query()
            ->where('klien_ar_id', $eb->klien_ar_id)
            ->whereBetween('tanggal_invoice', [
                $eb->periode_awal->toDateString(),
                $eb->periode_akhir->toDateString(),
            ])
            ->where(fn($q) => $q
                ->where('is_opening_balance', false)
                ->orWhere(fn($q2) => $q2->where('is_opening_balance', true)->where('approval_status', 'APPROVED'))
            )
            ->orderBy('tanggal_invoice')
            ->get()
            ->map(fn($inv) => [
                'id'                         => $inv->id,
                'no_invoice'                 => $inv->no_invoice,
                'tanggal_invoice'            => $inv->tanggal_invoice?->toDateString(),
                'tanggal_jatuh_tempo'        => $inv->tanggal_jatuh_tempo?->toDateString(),
                'is_opening_balance'         => (bool) $inv->is_opening_balance,
                'subtotal'                   => (float) $inv->subtotal,
                'tagihan_periode_sebelumnya' => (float) $inv->tagihan_periode_sebelumnya,
                'total_tagihan'              => (float) $inv->total_tagihan,
                'total_pembayaran'           => (float) $inv->total_pembayaran,
                'sisa_tagihan'               => (float) $inv->sisa_tagihan,
                'status'                     => $inv->status,
            ]);

        return $this->successResponse($invoices);
    }

    public function recalculate(int $id): JsonResponse
    {
        $this->authorizeOperate();

        $eb      = EndingBalance::findOrFail($id);
        $updated = $this->service->recalculate($eb, auth()->id());

        return $this->successResponse(
            $this->formatEb($updated),
            'Nilai EB berhasil dihitung ulang.'
        );
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function formatEb(EndingBalance $eb, bool $detailed = false): array
    {
        $stats = Invoice::query()
            ->where('klien_ar_id', $eb->klien_ar_id)
            ->whereBetween('tanggal_invoice', [
                $eb->periode_awal->toDateString(),
                $eb->periode_akhir->toDateString(),
            ])
            ->where(fn($q) => $q
                ->where('is_opening_balance', false)
                ->orWhere(fn($q2) => $q2->where('is_opening_balance', true)->where('approval_status', 'APPROVED'))
            )
            ->selectRaw(
                // Sisa riil per invoice = (subtotal - total_pembayaran), bukan
                // (total_tagihan - total_pembayaran). total_tagihan kumulatif berjalan
                // sehingga menjumlahkannya double-count carryover bulan berjalan.
                'SUM(GREATEST(0, CASE WHEN subtotal = 0 THEN sisa_tagihan ELSE subtotal - total_pembayaran END)) as outstanding,
                 SUM(CASE WHEN tanggal_jatuh_tempo IS NOT NULL AND tanggal_jatuh_tempo < ? THEN GREATEST(0, CASE WHEN subtotal = 0 THEN sisa_tagihan ELSE subtotal - total_pembayaran END) ELSE 0 END) as overdue,
                 COUNT(CASE WHEN (CASE WHEN subtotal = 0 THEN sisa_tagihan ELSE subtotal - total_pembayaran END) > 0 THEN 1 END) as outstanding_count,
                 COUNT(CASE WHEN (CASE WHEN subtotal = 0 THEN sisa_tagihan ELSE subtotal - total_pembayaran END) > 0 AND tanggal_jatuh_tempo IS NOT NULL AND tanggal_jatuh_tempo < ? THEN 1 END) as overdue_count',
                [now()->toDateString(), now()->toDateString()]
            )
            ->first();

        $base = [
            'id'                  => $eb->id,
            'klien_id'            => $eb->klien_ar_id,
            'kode_klien'          => $eb->klienAr?->kode_klien,
            'nama_klien'          => $eb->klienAr?->nama_klien,
            'perusahaan'          => $eb->klienAr?->perusahaan?->nama_singkatan_perusahaan,
            'periode_awal'        => $eb->periode_awal?->toDateString(),
            'periode_akhir'       => $eb->periode_akhir?->toDateString(),
            'saldo_awal'          => (float) $eb->saldo_awal,
            'invoice_masuk'       => (float) $eb->invoice_masuk,
            'pembayaran'          => (float) $eb->pembayaran,
            'saldo_akhir_sistem'  => (float) $eb->saldo_akhir_sistem,
            'saldo_akhir_final'   => (float) $eb->saldo_akhir_final,
            'outstanding'         => (float) ($stats->outstanding ?? 0),
            'overdue'             => (float) ($stats->overdue ?? 0),
            'outstanding_count'   => (int) ($stats->outstanding_count ?? 0),
            'overdue_count'       => (int) ($stats->overdue_count ?? 0),
            'status'              => $eb->status,
            'locked_at'           => $eb->locked_at?->toIso8601String(),
            'locked_by'           => $eb->lockedBy?->username,
            'has_active_koreksi'  => $eb->hasActiveKoreksi(),
            'created_at'          => $eb->created_at?->toIso8601String(),
        ];

        if ($detailed) {
            $base['koreksi'] = $eb->koreksi->map(fn($k) => [
                'id'                  => $k->id,
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
            ])->values()->all();
        }

        return $base;
    }

    private function authorizeView(): void
    {
        abort_if(
            !RoleHelper::canViewEndingBalance(auth()->user()),
            403, 'Tidak memiliki akses ke data ending balance.'
        );
    }

    private function authorizeOperate(): void
    {
        abort_if(
            !RoleHelper::canOperateEndingBalance(auth()->user()),
            403, 'Tidak memiliki akses untuk mengelola ending balance.'
        );
    }
}
