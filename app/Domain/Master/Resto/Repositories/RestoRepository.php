<?php

namespace App\Domain\Master\Resto\Repositories;

use App\Models\Resto;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class RestoRepository
{
    private function baseQuery()
    {
        return Resto::with(['investor', 'perusahaan', 'brand', 'pic', 'createdBy', 'updatedBy']);
    }

    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->baseQuery()
            ->when($filters['search'] ?? null, fn($q, $v) => $q->where(fn($q) => $q
                ->where('kode_resto', 'like', "%{$v}%")
                ->orWhere('nama_resto', 'like', "%{$v}%")
                ->orWhere('kota', 'like', "%{$v}%")
                ->orWhere('area', 'like', "%{$v}%")
            ))
            ->when(isset($filters['status']), fn($q) => $q->where('status', $filters['status']))
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('perusahaan_id', $v))
            ->when($filters['karyawan_id'] ?? null, fn($q, $v) => $q->where('karyawan_id', $v))
            ->latest()
            ->paginate($perPage);
    }

    public function findById(int $id): ?Resto
    {
        return $this->baseQuery()->find($id);
    }

    public function create(array $data): Resto
    {
        $resto = Resto::create($data);
        return $resto->load(['investor', 'perusahaan', 'brand', 'pic', 'createdBy', 'updatedBy']);
    }

    public function update(Resto $resto, array $data): Resto
    {
        $resto->update($data);
        return $resto->fresh(['investor', 'perusahaan', 'brand', 'pic', 'createdBy', 'updatedBy']);
    }

    public function delete(Resto $resto): bool
    {
        return (bool) $resto->forceDelete();
    }

    public function countByPerusahaanAndBrand(int $perusahaanId, int $brandId): int
    {
        return Resto::withTrashed()
            ->where('perusahaan_id', $perusahaanId)
            ->where('brand_id', $brandId)
            ->count();
    }
}
