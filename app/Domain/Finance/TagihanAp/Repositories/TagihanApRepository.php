<?php

namespace App\Domain\Finance\TagihanAp\Repositories;

use App\Models\TagihanAp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class TagihanApRepository
{
    public function paginate(array $filters = [], int $perPage = 15, ?array $with = null): LengthAwarePaginator
    {
        if (isset($filters['per_page']) && is_numeric($filters['per_page'])) {
            $perPage = max(1, (int) $filters['per_page']);
        }

        $relations = $with ?? ['vendorAp', 'perusahaan'];

        return $this->applyFilters(TagihanAp::with($relations), $filters)
            ->latest('tanggal_tagihan')
            ->paginate($perPage);
    }

    public function getAll(array $filters = []): Collection
    {
        return $this->applyFilters(
            TagihanAp::with(['vendorAp', 'perusahaan', 'createdBy']),
            $filters
        )->latest('tanggal_tagihan')->get();
    }

    public function findById(int $id): ?TagihanAp
    {
        return TagihanAp::with([
            'vendorAp.perusahaan',
            'vendorAp.karyawanAp',
            'perusahaan',
            'karyawan.perusahaan',
            'items.barang',
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
        $result = $this->applyFilters(TagihanAp::query(), $filters)
            ->selectRaw('
                COUNT(*) as total_tagihan,
                COALESCE(SUM(total_tagihan), 0) as total_nominal,
                COALESCE(SUM(total_pembayaran), 0) as total_pembayaran,
                COALESCE(SUM(sisa_tagihan), 0) as total_sisa
            ')
            ->first();

        return [
            'total_tagihan'    => (int) ($result?->total_tagihan ?? 0),
            'total_nominal'    => (float) ($result?->total_nominal ?? 0),
            'total_pembayaran' => (float) ($result?->total_pembayaran ?? 0),
            'total_sisa'       => (float) ($result?->total_sisa ?? 0),
        ];
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] ?? null, fn($q, $v) => $q->where(fn($q) => $q
                ->where('no_tagihan', 'like', "%{$v}%")
                ->orWhere('no_invoice_vendor', 'like', "%{$v}%")
                ->orWhereHas('vendorAp', fn($q) => $q
                    ->where('nama_vendor', 'like', "%{$v}%")
                    ->orWhere('kode_vendor', 'like', "%{$v}%")
                )
            ))
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('perusahaan_id', $v))
            ->when($filters['vendor_ap_id'] ?? null, fn($q, $v) => $q->where('vendor_ap_id', $v))
            ->when($filters['karyawan_id'] ?? null, fn($q, $v) => $q->where('karyawan_id', $v))
            ->when($filters['pic_ap_karyawan_id'] ?? null, fn($q, $v) =>
                $q->whereHas('vendorAp', fn($q) => $q->where('karyawan_ap_id', $v))
            )
            ->when($filters['status'] ?? null, fn($q, $v) => $q->where('status', $v))
            ->when($filters['approval_status'] ?? null, fn($q, $v) => $q->where('approval_status', $v))
            ->when(array_key_exists('is_opening_balance', $filters), fn($q) =>
                $q->where('is_opening_balance', $filters['is_opening_balance'])
            )
            ->when($filters['tanggal_dari'] ?? null, fn($q, $v) => $q->where('tanggal_tagihan', '>=', $v))
            ->when($filters['tanggal_sampai'] ?? null, fn($q, $v) => $q->where('tanggal_tagihan', '<=', $v));
    }
}
