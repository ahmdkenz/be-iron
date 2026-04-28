<?php

namespace App\Domain\Master\Karyawan\Repositories;

use App\Models\Karyawan;
use Illuminate\Pagination\LengthAwarePaginator;

class KaryawanRepository
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Karyawan::with(['perusahaan', 'createdBy', 'updatedBy'])
            ->when($filters['search'] ?? null, fn($q, $v) => $q->where(fn($q) => $q
                ->where('nik', 'like', "%{$v}%")
                ->orWhere('nama_karyawan', 'like', "%{$v}%")
            ))
            ->when(isset($filters['status']), fn($q) => $q->where('status', $filters['status']))
            ->latest()
            ->paginate($perPage);
    }

    public function findById(int $id): ?Karyawan
    {
        return Karyawan::with(['perusahaan', 'createdBy', 'updatedBy'])->find($id);
    }

    public function create(array $data): Karyawan
    {
        $karyawan = Karyawan::create($data);
        return $karyawan->load(['perusahaan', 'createdBy', 'updatedBy']);
    }

    public function update(Karyawan $karyawan, array $data): Karyawan
    {
        $karyawan->update($data);
        return $karyawan->fresh(['perusahaan', 'createdBy', 'updatedBy']);
    }

    public function delete(Karyawan $karyawan): bool
    {
        return (bool) $karyawan->forceDelete();
    }
}
