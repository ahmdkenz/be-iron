<?php

namespace App\Domain\Finance\KlienAr\Repositories;

use App\Models\KlienAr;
use Illuminate\Pagination\LengthAwarePaginator;

class KlienArRepository
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return KlienAr::with(['perusahaan', 'karyawanAr', 'createdBy', 'updatedBy'])
            ->when($filters['search'] ?? null, fn($q, $v) => $q->where(fn($q) => $q
                ->where('nama_klien', 'like', "%{$v}%")
                ->orWhere('kode_klien', 'like', "%{$v}%")
                ->orWhere('alias', 'like', "%{$v}%")
            ))
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('perusahaan_id', $v))
            ->when($filters['karyawan_ar_id'] ?? null, fn($q, $v) => $q->where('karyawan_ar_id', $v))
            ->when(isset($filters['status']), fn($q) => $q->where('status', $filters['status']))
            ->latest()
            ->paginate($perPage);
    }

    public function getAll(array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        return KlienAr::with(['perusahaan', 'karyawanAr'])
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('perusahaan_id', $v))
            ->when($filters['karyawan_ar_id'] ?? null, fn($q, $v) => $q->where('karyawan_ar_id', $v))
            ->where('status', true)
            ->orderBy('nama_klien')
            ->get();
    }

    public function findById(int $id): ?KlienAr
    {
        return KlienAr::with(['perusahaan', 'karyawanAr', 'resto', 'createdBy', 'updatedBy'])->find($id);
    }

    public function create(array $data): KlienAr
    {
        $klien = KlienAr::create($data);
        return $klien->load(['perusahaan', 'karyawanAr', 'createdBy', 'updatedBy']);
    }

    public function update(KlienAr $klien, array $data): KlienAr
    {
        $klien->update($data);
        return $klien->fresh(['perusahaan', 'karyawanAr', 'createdBy', 'updatedBy']);
    }

    public function delete(KlienAr $klien): bool
    {
        return (bool) $klien->forceDelete();
    }
}
