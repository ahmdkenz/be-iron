<?php

namespace App\Domain\Finance\VendorAp\Repositories;

use App\Models\VendorAp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class VendorApRepository
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        if (isset($filters['per_page']) && is_numeric($filters['per_page'])) {
            $perPage = max(1, (int) $filters['per_page']);
        }

        return $this->applyFilters(
            VendorAp::with(['perusahaan', 'karyawanAp', 'createdBy', 'updatedBy']),
            $filters
        )->latest()->paginate($perPage);
    }

    public function getAll(array $filters = []): Collection
    {
        return $this->applyFilters(VendorAp::with(['perusahaan', 'karyawanAp']), $filters)
            ->where('status', true)
            ->orderBy('nama_vendor')
            ->get();
    }

    public function findById(int $id): ?VendorAp
    {
        return VendorAp::with(['perusahaan', 'karyawanAp', 'createdBy', 'updatedBy'])->find($id);
    }

    public function create(array $data): VendorAp
    {
        $vendor = VendorAp::create($data);
        return $vendor->load(['perusahaan', 'karyawanAp', 'createdBy', 'updatedBy']);
    }

    public function update(VendorAp $vendor, array $data): VendorAp
    {
        $vendor->update($data);
        return $vendor->fresh(['perusahaan', 'karyawanAp', 'createdBy', 'updatedBy']);
    }

    public function delete(VendorAp $vendor): bool
    {
        return (bool) $vendor->delete();
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] ?? null, fn($q, $v) => $q->where(fn($q) => $q
                ->where('nama_vendor', 'like', "%{$v}%")
                ->orWhere('kode_vendor', 'like', "%{$v}%")
                ->orWhere('no_npwp', 'like', "%{$v}%")
            ))
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('perusahaan_id', $v))
            ->when($filters['karyawan_ap_id'] ?? null, fn($q, $v) => $q->where('karyawan_ap_id', $v))
            ->when($filters['pic_ap_karyawan_id'] ?? null, fn($q, $v) => $q->where('karyawan_ap_id', $v))
            ->when($filters['kategori'] ?? null, fn($q, $v) => $q->where('kategori', $v))
            ->when(isset($filters['status']), fn($q) => $q->where('status', $filters['status']));
    }
}
