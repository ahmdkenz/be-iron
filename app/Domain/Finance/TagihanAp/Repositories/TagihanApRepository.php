<?php

namespace App\Domain\Finance\TagihanAp\Repositories;

use App\Models\TagihanAp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TagihanApRepository
{
    public function paginate(array $filters = [], int $perPage = 15, ?array $with = null): LengthAwarePaginator
    {
        if (isset($filters['per_page']) && is_numeric($filters['per_page'])) {
            $perPage = max(1, (int) $filters['per_page']);
        }

        $relations = $with ?? ['vendorAp'];

        return $this->applyFilters(TagihanAp::with($relations), $filters)
            ->latest('tanggal_tagihan')
            ->paginate($perPage);
    }

    public function getAll(array $filters = [], ?array $with = null): Collection
    {
        return $this->applyFilters(
            TagihanAp::with($with ?? ['vendorAp', 'createdBy']),
            $filters
        )->latest('tanggal_tagihan')->get();
    }

    public function getExportIds(array $filters = []): \Illuminate\Support\Collection
    {
        return $this->applyFilters(TagihanAp::query(), $filters)->pluck('id');
    }

    public function findById(int $id): ?TagihanAp
    {
        return TagihanAp::with([
            'vendorAp',
            'vendorAp.karyawanAp',
            'perusahaan',
            'karyawan.perusahaan',
            'items.barang',
            'openingBalanceApDetails.items.barang',
            'pembayarans.createdBy',
            'createdBy',
            'submittedBy',
            'approvedBy',
            'rejectedBy',
            'updatedBy',
            'approvalLogs.actor',
        ])->find($id);
    }

    public function create(array $data): TagihanAp
    {
        return TagihanAp::create($data);
    }

    public function getSummary(array $filters = []): array
    {
        $today        = Carbon::today()->toDateString();
        $dueSoonLimit = Carbon::today()->addDays(7)->toDateString();
        $base         = $this->applyFilters(TagihanAp::query(), $filters);

        $totals = (clone $base)
            ->selectRaw('
                COUNT(*) as total_tagihan,
                COALESCE(SUM(total_tagihan), 0) as total_nominal,
                COALESCE(SUM(total_pembayaran), 0) as total_pembayaran,
                COALESCE(SUM(sisa_tagihan), 0) as total_sisa,
                COUNT(CASE WHEN sisa_tagihan > 0 THEN 1 END) as outstanding_count
            ')
            ->first();

        $overdue = $this->applyOverdueScope(clone $base, $today)
            ->selectRaw('COUNT(*) as overdue_count, COALESCE(SUM(sisa_tagihan), 0) as overdue_amount')
            ->first();

        $dueSoonAgg = $this->applyDueSoonScope(clone $base, $today, $dueSoonLimit)
            ->selectRaw('COUNT(*) as due_soon_count, COALESCE(SUM(sisa_tagihan), 0) as due_soon_amount')
            ->first();

        $dueSoon = $this->applyDueSoonScope(clone $base, $today, $dueSoonLimit)
            ->with('vendorAp')
            ->orderBy('tanggal_jatuh_tempo')
            ->limit(5)
            ->get(['id', 'no_tagihan', 'vendor_ap_id', 'tanggal_jatuh_tempo', 'sisa_tagihan'])
            ->map(fn($t) => [
                'id'                  => $t->id,
                'no_tagihan'          => $t->no_tagihan,
                'vendor_ap_id'        => $t->vendor_ap_id,
                'nama_vendor'         => $t->vendorAp?->nama_vendor,
                'tanggal_jatuh_tempo' => $t->tanggal_jatuh_tempo?->toDateString(),
                'sisa_tagihan'        => (float) $t->sisa_tagihan,
            ])
            ->values()
            ->all();

        $statusBreakdown = (clone $base)
            ->selectRaw('status, COUNT(*) as jumlah, COALESCE(SUM(sisa_tagihan), 0) as total_sisa')
            ->groupBy('status')
            ->get()
            ->map(fn($row) => [
                'status'     => $row->status,
                'jumlah'     => (int) $row->jumlah,
                'total_sisa' => (float) $row->total_sisa,
            ])
            ->values()
            ->all();

        $approvalBreakdown = (clone $base)
            ->selectRaw('approval_status, COUNT(*) as jumlah, COALESCE(SUM(sisa_tagihan), 0) as total_sisa')
            ->groupBy('approval_status')
            ->get()
            ->map(fn($row) => [
                'approval_status' => $row->approval_status,
                'jumlah'          => (int) $row->jumlah,
                'total_sisa'      => (float) $row->total_sisa,
            ])
            ->values()
            ->all();

        $topVendors = (clone $base)
            ->where('tb_tagihan_ap.sisa_tagihan', '>', 0)
            ->join('tb_vendor_ap', 'tb_vendor_ap.id', '=', 'tb_tagihan_ap.vendor_ap_id')
            ->groupBy('tb_tagihan_ap.vendor_ap_id', 'tb_vendor_ap.nama_vendor', 'tb_vendor_ap.kode_vendor')
            ->orderByDesc('total_sisa')
            ->limit(5)
            ->get([
                'tb_tagihan_ap.vendor_ap_id',
                'tb_vendor_ap.nama_vendor',
                'tb_vendor_ap.kode_vendor',
                DB::raw('COALESCE(SUM(tb_tagihan_ap.sisa_tagihan), 0) as total_sisa'),
                DB::raw('COUNT(*) as jumlah_tagihan'),
            ]);

        $totalNominal    = (float) ($totals?->total_nominal ?? 0);
        $totalPembayaran = (float) ($totals?->total_pembayaran ?? 0);

        return [
            'total_tagihan'      => (int) ($totals?->total_tagihan ?? 0),
            'total_nominal'      => $totalNominal,
            'total_pembayaran'   => $totalPembayaran,
            'total_sisa'         => (float) ($totals?->total_sisa ?? 0),
            'payment_rate_pct'   => $totalNominal > 0 ? round($totalPembayaran / $totalNominal * 100, 2) : 0.0,
            'outstanding_count'  => (int) ($totals?->outstanding_count ?? 0),
            'overdue_count'      => (int) ($overdue?->overdue_count ?? 0),
            'overdue_amount'     => (float) ($overdue?->overdue_amount ?? 0),
            'due_soon_count'     => (int) ($dueSoonAgg?->due_soon_count ?? 0),
            'due_soon_amount'    => (float) ($dueSoonAgg?->due_soon_amount ?? 0),
            'status_breakdown'   => $statusBreakdown,
            'approval_breakdown' => $approvalBreakdown,
            'top_vendors'        => $topVendors->map(fn($v) => [
                'vendor_ap_id'   => $v->vendor_ap_id,
                'nama_vendor'    => $v->nama_vendor,
                'kode_vendor'    => $v->kode_vendor,
                'total_sisa'     => (float) $v->total_sisa,
                'jumlah_tagihan' => (int) $v->jumlah_tagihan,
            ])->values()->all(),
            'due_soon' => $dueSoon,
        ];
    }

    private function applyOverdueScope(Builder $query, string $today): Builder
    {
        return $query
            ->where('sisa_tagihan', '>', 0)
            ->where('approval_status', 'APPROVED')
            ->whereNotNull('tanggal_jatuh_tempo')
            ->where('tanggal_jatuh_tempo', '<', $today);
    }

    private function applyDueSoonScope(Builder $query, string $today, string $dueSoonLimit): Builder
    {
        return $query
            ->where('sisa_tagihan', '>', 0)
            ->where('approval_status', 'APPROVED')
            ->whereNotNull('tanggal_jatuh_tempo')
            ->whereBetween('tanggal_jatuh_tempo', [$today, $dueSoonLimit]);
    }

    public function delete(TagihanAp $tagihan): bool
    {
        return (bool) $tagihan->delete();
    }

    /**
     * Kolom di-qualify dengan nama tabel karena getSummary() bisa men-join
     * tb_vendor_ap (untuk top_vendors) yang JUGA punya kolom 'status' dan
     * 'perusahaan_id' — tanpa prefix, filter 'status'/'perusahaan_id' akan
     * memicu "ambiguous column" begitu join itu aktif.
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] ?? null, fn($q, $v) => $q->where(fn($q) => $q
                ->where('tb_tagihan_ap.no_tagihan', 'like', "%{$v}%")
                ->orWhere('tb_tagihan_ap.no_invoice_vendor', 'like', "%{$v}%")
                ->orWhereHas('vendorAp', fn($q) => $q
                    ->where('nama_vendor', 'like', "%{$v}%")
                    ->orWhere('kode_vendor', 'like', "%{$v}%")
                )
            ))
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('tb_tagihan_ap.perusahaan_id', $v))
            ->when($filters['vendor_ap_id'] ?? null, fn($q, $v) => $q->where('tb_tagihan_ap.vendor_ap_id', $v))
            ->when($filters['karyawan_id'] ?? null, fn($q, $v) => $q->where('tb_tagihan_ap.karyawan_id', $v))
            ->when($filters['pic_ap_karyawan_id'] ?? null, fn($q, $v) =>
                $q->whereHas('vendorAp', fn($q) => $q->where('karyawan_ap_id', $v))
            )
            ->when($filters['status'] ?? null, fn($q, $v) => $q->where('tb_tagihan_ap.status', $v))
            ->when($filters['approval_status'] ?? null, fn($q, $v) => $q->where('tb_tagihan_ap.approval_status', $v))
            ->when(array_key_exists('is_opening_balance', $filters), fn($q) =>
                $q->where('tb_tagihan_ap.is_opening_balance', $filters['is_opening_balance'])
            )
            ->when($filters['tanggal_dari'] ?? null, fn($q, $v) => $q->where('tb_tagihan_ap.tanggal_tagihan', '>=', $v))
            ->when($filters['tanggal_sampai'] ?? null, fn($q, $v) => $q->where('tb_tagihan_ap.tanggal_tagihan', '<=', $v));
    }
}
