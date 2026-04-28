<?php

namespace App\Domain\Master\Perusahaan\Repositories;

use App\Models\Perusahaan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class PerusahaanRepository
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Perusahaan::with(['createdBy', 'updatedBy'])
            ->when($filters['search'] ?? null, fn($q, $v) => $q->where(fn($q) => $q
                ->where('kode_perusahaan', 'like', "%{$v}%")
                ->orWhere('nama_perusahaan', 'like', "%{$v}%")
                ->orWhere('nama_singkatan_perusahaan', 'like', "%{$v}%")
            ))
            ->when(isset($filters['status']), fn($q) => $q->where('status', $filters['status']))
            ->latest()
            ->paginate($perPage);
    }

    public function all(array $filters = []): Collection
    {
        return Perusahaan::where('status', true)
            ->orderBy('nama_perusahaan')
            ->get(['id', 'kode_perusahaan', 'nama_perusahaan', 'nama_singkatan_perusahaan']);
    }

    public function findById(int $id): ?Perusahaan
    {
        return Perusahaan::with(['createdBy', 'updatedBy'])->find($id);
    }

    public function create(array $data): Perusahaan
    {
        return Perusahaan::create($data);
    }

    public function update(Perusahaan $perusahaan, array $data): Perusahaan
    {
        $perusahaan->update($data);
        return $perusahaan->fresh(['createdBy', 'updatedBy']);
    }

    public function delete(Perusahaan $perusahaan): bool
    {
        return (bool) $perusahaan->forceDelete();
    }
}
