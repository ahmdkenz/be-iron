<?php

namespace App\Domain\Master\Resto\Repositories;

use App\Models\Resto;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class RestoRepository
{
    private function baseQuery()
    {
        return Resto::with(['investor', 'perusahaan', 'perusahaan.klienArs.karyawanAr', 'brand', 'pic', 'klienArs.karyawanAr', 'createdBy', 'updatedBy']);
    }

    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        if ($perPage <= 0) $perPage = PHP_INT_MAX;

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

    public function create(array $data, bool $eagerLoad = true): Resto
    {
        $resto = Resto::create($data);
        return $eagerLoad
            ? $resto->load(['investor', 'perusahaan', 'perusahaan.klienArs.karyawanAr', 'brand', 'pic', 'klienArs.karyawanAr', 'createdBy', 'updatedBy'])
            : $resto;
    }

    public function update(Resto $resto, array $data, bool $eagerLoad = true): Resto
    {
        $resto->update($data);
        return $eagerLoad
            ? $resto->fresh(['investor', 'perusahaan', 'perusahaan.klienArs.karyawanAr', 'brand', 'pic', 'klienArs.karyawanAr', 'createdBy', 'updatedBy'])
            : $resto;
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

    public function getAllForExport(array $filters = []): EloquentCollection
    {
        return $this->baseQuery()
            ->when($filters['search'] ?? null, fn($q, $v) => $q->where(fn($q) => $q
                ->where('kode_resto', 'like', "%{$v}%")
                ->orWhere('nama_resto', 'like', "%{$v}%")
                ->orWhere('kota', 'like', "%{$v}%")
                ->orWhere('area', 'like', "%{$v}%")
            ))
            ->when(isset($filters['status']), fn($q) => $q->where('status', $filters['status']))
            ->latest()
            ->get();
    }
}
