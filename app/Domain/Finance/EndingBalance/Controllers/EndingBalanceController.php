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
            'segment'       => ['nullable', 'in:B2B,B2C'],
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

        // Batch-load outstanding/overdue stats for all rows in one query (avoids N+1)
        $statsMap = $this->batchLoadStats($list->getCollection());

        return $this->paginatedResponse(
            $list->through(fn($eb) => $this->formatEb($eb, statsMap: $statsMap))
        );
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeView();

        $eb = EndingBalance::with(['klienAr.perusahaan', 'koreksi.submittedBy', 'koreksi.spv', 'koreksi.manager', 'koreksi.invoice', 'koreksi.items', 'lockedBy', 'createdBy'])
            ->findOrFail($id);

        return $this->successResponse($this->formatEb($eb, detailed: true));
    }

    public function lock(Request $request, int $id): JsonResponse
    {
        $this->authorizeLock();

        $eb      = EndingBalance::findOrFail($id);
        $updated = $this->service->lock($eb, auth()->id());

        return $this->successResponse(
            $this->formatEb($updated),
            'Ending balance berhasil dikunci.'
        );
    }

    public function unlock(Request $request, int $id): JsonResponse
    {
        $this->authorizeLock();

        $eb      = EndingBalance::findOrFail($id);
        $updated = $this->service->unlock($eb, auth()->id());

        return $this->successResponse(
            $this->formatEb($updated),
            'Ending balance berhasil dibuka kembali.'
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
            ->with(['endingBalanceKoreksi' => fn($q) => $q
                ->whereIn('tipe', ['CREDIT_NOTE', 'DEBIT_NOTE'])
                ->where('status', 'APPROVED')
            ])
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
                'total_penyesuaian'          => (float) $inv->total_penyesuaian,
                'sisa_tagihan'               => (float) $inv->sisa_tagihan,
                'status'                     => $inv->status,
                'total_cn'                   => (float) $inv->endingBalanceKoreksi
                                                    ->where('tipe', 'CREDIT_NOTE')
                                                    ->sum(fn($k) => abs((float) $k->nilai_koreksi)),
                'total_dn'                   => (float) $inv->endingBalanceKoreksi
                                                    ->where('tipe', 'DEBIT_NOTE')
                                                    ->sum(fn($k) => abs((float) $k->nilai_koreksi)),
            ]);

        return $this->successResponse($invoices);
    }

    public function payments(int $id): JsonResponse
    {
        $this->authorizeView();

        $eb = EndingBalance::findOrFail($id);

        $periodeAwal  = $eb->periode_awal->toDateString();
        $periodeAkhir = $eb->periode_akhir->toDateString();

        // Pembayaran reguler (invoice milik klien ini)
        $regular = \DB::table('tb_pembayaran_ar as p')
            ->join('tb_invoice as i', 'p.invoice_id', '=', 'i.id')
            ->where('i.klien_ar_id', $eb->klien_ar_id)
            ->whereBetween('p.tanggal_pembayaran', [$periodeAwal, $periodeAkhir])
            ->select([
                'p.id',
                'i.no_invoice',
                'p.tanggal_pembayaran',
                'p.jumlah_pembayaran',
                'p.metode_pembayaran',
                'p.no_referensi',
                'p.keterangan',
                \DB::raw("NULL as nama_klien_tujuan"),
            ])
            ->get()
            ->map(fn($row) => $this->resolvePaymentJenis($row));

        // ALO cross-klien: sumber invoice milik klien ini, tujuan klien lain (B2C/investor)
        $crossKlien = \DB::table('tb_pembayaran_ar as p')
            ->join('tb_invoice as i', 'p.invoice_id', '=', 'i.id')
            ->join('tb_klien_ar as ka', 'i.klien_ar_id', '=', 'ka.id')
            ->join('tb_pembayaran_ar as src', 'p.sumber_pembayaran_ar_id', '=', 'src.id')
            ->join('tb_invoice as src_i', 'src.invoice_id', '=', 'src_i.id')
            ->where('src_i.klien_ar_id', $eb->klien_ar_id)
            ->where('i.klien_ar_id', '!=', $eb->klien_ar_id)
            ->whereBetween('p.tanggal_pembayaran', [$periodeAwal, $periodeAkhir])
            ->where('p.no_referensi', 'LIKE', '%/ALO-%')
            ->select([
                'p.id',
                'src_i.no_invoice',
                'p.tanggal_pembayaran',
                'p.jumlah_pembayaran',
                'p.metode_pembayaran',
                'p.no_referensi',
                'p.keterangan',
                'ka.nama_klien as nama_klien_tujuan',
            ])
            ->get()
            ->map(fn($row) => array_merge((array) $row, ['jenis' => 'ALO_CROSS']));

        $payments = $regular->concat($crossKlien)->sortBy('tanggal_pembayaran')->values();

        return $this->successResponse($payments);
    }

    private function resolvePaymentJenis(object $row): array
    {
        $ref = $row->no_referensi ?? '';
        $jenis = match (true) {
            str_contains($ref, '/PDM-') => 'PDM',
            str_contains($ref, '/ALO-') => 'ALO',
            default                     => 'REGULER',
        };

        return array_merge((array) $row, ['jenis' => $jenis, 'nama_klien_tujuan' => null]);
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

        $rows = \DB::table('tb_invoice as i')
            ->join('tb_ending_balance as eb', function ($join) {
                $join->on('i.klien_ar_id', '=', 'eb.klien_ar_id')
                     ->whereColumn('i.tanggal_invoice', '>=', 'eb.periode_awal')
                     ->whereColumn('i.tanggal_invoice', '<=', 'eb.periode_akhir');
            })
            ->whereIn('eb.id', $ids)
            ->where(fn($q) => $q
                ->where('i.is_opening_balance', false)
                ->orWhere(fn($q2) => $q2->where('i.is_opening_balance', true)->where('i.approval_status', 'APPROVED'))
            )
            ->groupBy('eb.id')
            ->selectRaw(
                'eb.id as eb_id,
                 SUM(GREATEST(0, CASE WHEN i.subtotal = 0 THEN i.sisa_tagihan ELSE i.subtotal - i.total_pembayaran - i.total_penyesuaian END)) as outstanding,
                 SUM(CASE WHEN i.tanggal_jatuh_tempo IS NOT NULL AND i.tanggal_jatuh_tempo < ?
                     THEN GREATEST(0, CASE WHEN i.subtotal = 0 THEN i.sisa_tagihan ELSE i.subtotal - i.total_pembayaran - i.total_penyesuaian END)
                     ELSE 0 END) as overdue,
                 COUNT(CASE WHEN (CASE WHEN i.subtotal = 0 THEN i.sisa_tagihan ELSE i.subtotal - i.total_pembayaran - i.total_penyesuaian END) > 0 THEN 1 END) as outstanding_count,
                 COUNT(CASE WHEN (CASE WHEN i.subtotal = 0 THEN i.sisa_tagihan ELSE i.subtotal - i.total_pembayaran - i.total_penyesuaian END) > 0
                     AND i.tanggal_jatuh_tempo IS NOT NULL AND i.tanggal_jatuh_tempo < ? THEN 1 END) as overdue_count',
                [$today, $today]
            )
            ->get();

        return $rows->keyBy('eb_id')->all();
    }

    private function formatEb(EndingBalance $eb, bool $detailed = false, array $statsMap = []): array
    {
        // Use pre-loaded batch stats when available (index), otherwise query per-row (show/recalculate)
        $stats = $statsMap[$eb->id] ?? Invoice::query()
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
                'SUM(GREATEST(0, CASE WHEN subtotal = 0 THEN sisa_tagihan ELSE subtotal - total_pembayaran - total_penyesuaian END)) as outstanding,
                 SUM(CASE WHEN tanggal_jatuh_tempo IS NOT NULL AND tanggal_jatuh_tempo < ? THEN GREATEST(0, CASE WHEN subtotal = 0 THEN sisa_tagihan ELSE subtotal - total_pembayaran - total_penyesuaian END) ELSE 0 END) as overdue,
                 COUNT(CASE WHEN (CASE WHEN subtotal = 0 THEN sisa_tagihan ELSE subtotal - total_pembayaran - total_penyesuaian END) > 0 THEN 1 END) as outstanding_count,
                 COUNT(CASE WHEN (CASE WHEN subtotal = 0 THEN sisa_tagihan ELSE subtotal - total_pembayaran - total_penyesuaian END) > 0 AND tanggal_jatuh_tempo IS NOT NULL AND tanggal_jatuh_tempo < ? THEN 1 END) as overdue_count',
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
            'created_by'          => $eb->createdBy?->username,
            'updated_at'          => $eb->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $base['koreksi'] = $eb->koreksi->map(fn($k) => [
                'id'                  => $k->id,
                'invoice_id'          => $k->invoice_id,
                'no_invoice'          => $k->invoice?->no_invoice,
                'tipe'                => $k->tipe,
                'no_dokumen'          => $k->no_dokumen,
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
                'items'               => $k->relationLoaded('items')
                    ? $k->items->map(fn($i) => [
                        'id'                => $i->id,
                        'invoice_item_id'   => $i->invoice_item_id,
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

    private function authorizeLock(): void
    {
        abort_if(
            !RoleHelper::canLockEndingBalance(auth()->user()),
            403, 'Tidak memiliki akses untuk mengunci/membuka ending balance.'
        );
    }
}
