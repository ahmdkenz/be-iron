<?php

namespace App\Domain\Finance\EndingBalanceAp\Controllers;

use App\Domain\Finance\EndingBalanceAp\Services\EndingBalanceApService;
use App\Http\Controllers\Controller;
use App\Models\EndingBalanceAp;
use App\Models\TagihanAp;
use App\Support\Helpers\ApFilterScope;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EndingBalanceApController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly EndingBalanceApService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView();

        $filters = $request->validate([
            'vendor_ap_id'  => ['nullable', 'integer', 'exists:tb_vendor_ap,id'],
            'status'        => ['nullable', 'in:DRAFT,LOCKED'],
            'periode_awal'  => ['nullable', 'date'],
            'periode_akhir' => ['nullable', 'date'],
            'per_page'      => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        ApFilterScope::apply($filters, auth()->user());

        $list = $this->service->paginate($filters);

        $statsMap = $this->batchLoadStats($list->getCollection());

        return $this->paginatedResponse(
            $list->through(fn($eb) => $this->formatEb($eb, statsMap: $statsMap))
        );
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeView();

        $eb = EndingBalanceAp::with([
            'vendorAp',
            'perusahaan',
            'koreksi.submittedBy',
            'koreksi.approvedBy',
            'koreksi.tagihanAp',
            'koreksi.items',
            'lockedBy',
            'createdBy',
        ])->findOrFail($id);

        $this->authorizePicApVendor($eb->vendorAp?->karyawan_ap_id);

        return $this->successResponse($this->formatEb($eb, detailed: true));
    }

    public function lock(Request $request, int $id): JsonResponse
    {
        $this->authorizeLock();

        $eb      = EndingBalanceAp::findOrFail($id);
        $updated = $this->service->lock($eb, auth()->id());

        return $this->successResponse(
            $this->formatEb($updated),
            'Ending balance berhasil dikunci.'
        );
    }

    public function unlock(Request $request, int $id): JsonResponse
    {
        $this->authorizeLock();

        $eb      = EndingBalanceAp::findOrFail($id);
        $updated = $this->service->unlock($eb, auth()->id());

        return $this->successResponse(
            $this->formatEb($updated),
            'Ending balance berhasil dibuka kembali.'
        );
    }

    public function recalculate(int $id): JsonResponse
    {
        $this->authorizeOperate();

        $eb      = EndingBalanceAp::findOrFail($id);
        $updated = $this->service->recalculate($eb, auth()->id());

        return $this->successResponse(
            $this->formatEb($updated),
            'Nilai EB berhasil dihitung ulang.'
        );
    }

    public function tagihan(int $id): JsonResponse
    {
        $this->authorizeView();

        $eb = EndingBalanceAp::findOrFail($id);
        $this->authorizePicApVendor($eb->vendorAp?->karyawan_ap_id);

        $rows = TagihanAp::query()
            ->where('vendor_ap_id', $eb->vendor_ap_id)
            ->where('perusahaan_id', $eb->perusahaan_id)
            ->whereBetween('tanggal_tagihan', [
                $eb->periode_awal->toDateString(),
                $eb->periode_akhir->toDateString(),
            ])
            ->where(fn($q) => $q
                ->where('is_opening_balance', false)
                ->orWhere(fn($q2) => $q2->where('is_opening_balance', true)->where('approval_status', 'APPROVED'))
            )
            ->with(['endingBalanceApKoreksi' => fn($q) => $q
                ->whereIn('tipe', ['CREDIT_NOTE', 'DEBIT_NOTE'])
                ->where('status', 'APPROVED')
            ])
            ->orderBy('tanggal_tagihan')
            ->get()
            ->map(fn($t) => [
                'id'                  => $t->id,
                'no_tagihan'          => $t->no_tagihan,
                'tanggal_tagihan'     => $t->tanggal_tagihan?->toDateString(),
                'tanggal_jatuh_tempo' => $t->tanggal_jatuh_tempo?->toDateString(),
                'is_opening_balance'  => (bool) $t->is_opening_balance,
                'total_tagihan'       => (float) $t->total_tagihan,
                'total_pembayaran'    => (float) $t->total_pembayaran,
                'total_penyesuaian'   => (float) $t->total_penyesuaian,
                'sisa_tagihan'        => (float) $t->sisa_tagihan,
                'status'              => $t->status,
                'total_cn'            => (float) $t->endingBalanceApKoreksi
                                            ->where('tipe', 'CREDIT_NOTE')
                                            ->sum(fn($k) => abs((float) $k->nilai_koreksi)),
                'total_dn'            => (float) $t->endingBalanceApKoreksi
                                            ->where('tipe', 'DEBIT_NOTE')
                                            ->sum(fn($k) => abs((float) $k->nilai_koreksi)),
            ]);

        return $this->successResponse($rows);
    }

    public function pembayaran(int $id): JsonResponse
    {
        $this->authorizeView();

        $eb = EndingBalanceAp::findOrFail($id);
        $this->authorizePicApVendor($eb->vendorAp?->karyawan_ap_id);

        $rows = DB::table('tb_pembayaran_ap_items as pai')
            ->join('tb_pembayaran_ap as pa', 'pai.pembayaran_ap_id', '=', 'pa.id')
            ->join('tb_tagihan_ap as ta', 'pai.tagihan_ap_id', '=', 'ta.id')
            ->where('pai.vendor_ap_id', $eb->vendor_ap_id)
            ->where('ta.perusahaan_id', $eb->perusahaan_id)
            ->whereBetween('pa.tanggal_pembayaran', [
                $eb->periode_awal->toDateString(),
                $eb->periode_akhir->toDateString(),
            ])
            ->select([
                'pai.id',
                'ta.no_tagihan',
                'pa.tanggal_pembayaran',
                'pai.jumlah_dialokasikan as jumlah_pembayaran',
                'pa.metode_pembayaran',
                'pa.no_referensi',
                'pa.keterangan',
            ])
            ->orderBy('pa.tanggal_pembayaran')
            ->get();

        return $this->successResponse($rows);
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    /**
     * Batch-load outstanding/overdue stats for a collection of EB rows in one query.
     * Returns array keyed by EB id.
     */
    private function batchLoadStats(\Illuminate\Support\Collection $ebs): array
    {
        if ($ebs->isEmpty()) {
            return [];
        }

        $ids   = $ebs->pluck('id')->all();
        $today = now()->toDateString();

        $rows = DB::table('tb_tagihan_ap as t')
            ->join('tb_ending_balance_ap as eb', function ($join) {
                $join->on('t.vendor_ap_id', '=', 'eb.vendor_ap_id')
                     ->on('t.perusahaan_id', '=', 'eb.perusahaan_id')
                     ->whereColumn('t.tanggal_tagihan', '>=', 'eb.periode_awal')
                     ->whereColumn('t.tanggal_tagihan', '<=', 'eb.periode_akhir');
            })
            ->whereIn('eb.id', $ids)
            ->where(fn($q) => $q
                ->where('t.is_opening_balance', false)
                ->orWhere(fn($q2) => $q2->where('t.is_opening_balance', true)->where('t.approval_status', 'APPROVED'))
            )
            ->groupBy('eb.id')
            ->selectRaw(
                'eb.id as eb_id,
                 SUM(GREATEST(0, t.total_tagihan - t.total_pembayaran - t.total_penyesuaian)) as outstanding,
                 SUM(CASE WHEN t.tanggal_jatuh_tempo IS NOT NULL AND t.tanggal_jatuh_tempo < ?
                     THEN GREATEST(0, t.total_tagihan - t.total_pembayaran - t.total_penyesuaian)
                     ELSE 0 END) as overdue,
                 COUNT(CASE WHEN (t.total_tagihan - t.total_pembayaran - t.total_penyesuaian) > 0 THEN 1 END) as outstanding_count,
                 COUNT(CASE WHEN (t.total_tagihan - t.total_pembayaran - t.total_penyesuaian) > 0
                     AND t.tanggal_jatuh_tempo IS NOT NULL AND t.tanggal_jatuh_tempo < ? THEN 1 END) as overdue_count',
                [$today, $today]
            )
            ->get();

        return $rows->keyBy('eb_id')->all();
    }

    private function formatEb(EndingBalanceAp $eb, bool $detailed = false, array $statsMap = []): array
    {
        $stats = $statsMap[$eb->id] ?? TagihanAp::query()
            ->where('vendor_ap_id', $eb->vendor_ap_id)
            ->where('perusahaan_id', $eb->perusahaan_id)
            ->whereBetween('tanggal_tagihan', [
                $eb->periode_awal->toDateString(),
                $eb->periode_akhir->toDateString(),
            ])
            ->where(fn($q) => $q
                ->where('is_opening_balance', false)
                ->orWhere(fn($q2) => $q2->where('is_opening_balance', true)->where('approval_status', 'APPROVED'))
            )
            ->selectRaw(
                'SUM(GREATEST(0, total_tagihan - total_pembayaran - total_penyesuaian)) as outstanding,
                 SUM(CASE WHEN tanggal_jatuh_tempo IS NOT NULL AND tanggal_jatuh_tempo < ? THEN GREATEST(0, total_tagihan - total_pembayaran - total_penyesuaian) ELSE 0 END) as overdue,
                 COUNT(CASE WHEN (total_tagihan - total_pembayaran - total_penyesuaian) > 0 THEN 1 END) as outstanding_count,
                 COUNT(CASE WHEN (total_tagihan - total_pembayaran - total_penyesuaian) > 0 AND tanggal_jatuh_tempo IS NOT NULL AND tanggal_jatuh_tempo < ? THEN 1 END) as overdue_count',
                [now()->toDateString(), now()->toDateString()]
            )
            ->first();

        $base = [
            'id'                 => $eb->id,
            'vendor_id'          => $eb->vendor_ap_id,
            'kode_vendor'        => $eb->vendorAp?->kode_vendor,
            'nama_vendor'        => $eb->vendorAp?->nama_vendor,
            'perusahaan'         => $eb->perusahaan?->nama_singkatan_perusahaan,
            'periode_awal'       => $eb->periode_awal?->toDateString(),
            'periode_akhir'      => $eb->periode_akhir?->toDateString(),
            'saldo_awal'         => (float) $eb->saldo_awal,
            'tagihan_masuk'      => (float) $eb->tagihan_masuk,
            'pembayaran'         => (float) $eb->pembayaran,
            'saldo_akhir_sistem' => (float) $eb->saldo_akhir_sistem,
            'saldo_akhir_final'  => (float) $eb->saldo_akhir_final,
            'outstanding'        => (float) ($stats->outstanding ?? 0),
            'overdue'            => (float) ($stats->overdue ?? 0),
            'outstanding_count'  => (int) ($stats->outstanding_count ?? 0),
            'overdue_count'      => (int) ($stats->overdue_count ?? 0),
            'status'             => $eb->status,
            'locked_at'          => $eb->locked_at?->toIso8601String(),
            'locked_by'          => $eb->lockedBy?->username,
            'has_active_koreksi' => $eb->hasActiveKoreksi(),
            'created_at'         => $eb->created_at?->toIso8601String(),
            'created_by'         => $eb->createdBy?->username,
            'updated_at'         => $eb->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $base['koreksi'] = $eb->koreksi->map(fn($k) => [
                'id'             => $k->id,
                'tagihan_ap_id'  => $k->tagihan_ap_id,
                'no_tagihan'     => $k->tagihanAp?->no_tagihan,
                'tipe'           => $k->tipe,
                'no_dokumen'     => $k->no_dokumen,
                'nilai_koreksi'  => (float) $k->nilai_koreksi,
                'alasan_koreksi' => $k->alasan_koreksi,
                'dokumen_url'    => $k->dokumen_url,
                'status'         => $k->status,
                'submitted_by'   => $k->submittedBy?->username,
                'submitted_at'   => $k->submitted_at?->toIso8601String(),
                'approved_by'    => $k->approvedBy?->username,
                'approved_note'  => $k->approved_note,
                'approved_at'    => $k->approved_at?->toIso8601String(),
                'items'          => $k->relationLoaded('items')
                    ? $k->items->map(fn($i) => [
                        'id'                => $i->id,
                        'tagihan_ap_item_id' => $i->tagihan_ap_item_id,
                        'nama_barang'       => $i->nama_barang,
                        'qty_lama'          => (float) $i->qty_lama,
                        'harga_satuan_lama' => (float) $i->harga_satuan_lama,
                        'subtotal_lama'     => (float) $i->subtotal_lama,
                        'qty_baru'          => (float) $i->qty_baru,
                        'harga_satuan_baru' => (float) $i->harga_satuan_baru,
                        'subtotal_baru'     => (float) $i->subtotal_baru,
                        'selisih'           => (float) $i->selisih,
                    ])->values()->all()
                    : [],
            ])->values()->all();
        }

        return $base;
    }

    private function authorizeView(): void
    {
        abort_if(
            !RoleHelper::canViewEndingBalanceAp(auth()->user()),
            403, 'Tidak memiliki akses ke data ending balance AP.'
        );
    }

    private function authorizeOperate(): void
    {
        abort_if(
            !RoleHelper::canOperateEndingBalanceAp(auth()->user()),
            403, 'Tidak memiliki akses untuk mengelola ending balance AP.'
        );
    }

    private function authorizeLock(): void
    {
        abort_if(
            !RoleHelper::canLockEndingBalanceAp(auth()->user()),
            403, 'Tidak memiliki akses untuk mengunci/membuka ending balance AP.'
        );
    }

    private function authorizePicApVendor(?int $karyawanApId): void
    {
        if (!RoleHelper::isApStaff(auth()->user())) {
            return;
        }

        $user = auth()->user();
        abort_if(
            (int) $user->karyawan_id !== (int) $karyawanApId,
            403,
            'Anda hanya dapat melihat ending balance vendor yang ditugaskan kepada Anda'
        );
    }
}
