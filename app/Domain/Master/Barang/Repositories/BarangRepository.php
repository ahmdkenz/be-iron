<?php

namespace App\Domain\Master\Barang\Repositories;

use App\Models\Barang;
use Illuminate\Pagination\LengthAwarePaginator;

class BarangRepository
{
    private function baseQuery()
    {
        return Barang::with(['perusahaan', 'brand', 'createdBy', 'updatedBy']);
    }

    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->baseQuery()
            ->when($filters['search'] ?? null, fn($q, $v) => $q->where(fn($q) => $q
                ->where('kode_barang', 'like', "%{$v}%")
                ->orWhere('nama_barang', 'like', "%{$v}%")
            ))
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('perusahaan_id', $v))
            ->when($filters['brand_id'] ?? null, fn($q, $v) => $q->where('brand_id', $v))
            ->when(isset($filters['status']), fn($q) => $q->where('status', $filters['status']))
            ->latest()
            ->paginate($perPage);
    }

    public function findById(int $id): ?Barang
    {
        return $this->baseQuery()->find($id);
    }

    public function create(array $data): Barang
    {
        return Barang::create($data)->load(['perusahaan', 'brand', 'createdBy', 'updatedBy']);
    }

    public function update(Barang $barang, array $data): Barang
    {
        $barang->update($data);
        return $barang->fresh(['perusahaan', 'brand', 'createdBy', 'updatedBy']);
    }

    public function delete(Barang $barang): bool
    {
        return (bool) $barang->forceDelete();
    }
}
