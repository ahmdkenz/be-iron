<?php

namespace App\Domain\Master\Brand\Repositories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class BrandRepository
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Brand::with(['createdBy', 'updatedBy'])
            ->when($filters['search'] ?? null, fn($q, $v) => $q->where(fn($q) => $q
                ->where('kode_brand', 'like', "%{$v}%")
                ->orWhere('nama_brand', 'like', "%{$v}%")
            ))
            ->when(isset($filters['status']), fn($q) => $q->where('status', $filters['status']))
            ->latest()
            ->paginate($perPage);
    }

    public function all(): Collection
    {
        return Brand::where('status', true)
            ->orderBy('nama_brand')
            ->get(['id', 'kode_brand', 'nama_brand']);
    }

    public function findById(int $id): ?Brand
    {
        return Brand::with(['createdBy', 'updatedBy'])->find($id);
    }

    public function create(array $data): Brand
    {
        return Brand::create($data)->load(['createdBy', 'updatedBy']);
    }

    public function update(Brand $brand, array $data): Brand
    {
        $brand->update($data);
        return $brand->fresh(['createdBy', 'updatedBy']);
    }

    public function delete(Brand $brand): bool
    {
        return (bool) $brand->forceDelete();
    }
}
