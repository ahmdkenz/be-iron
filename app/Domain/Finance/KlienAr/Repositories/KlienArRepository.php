<?php

namespace App\Domain\Finance\KlienAr\Repositories;

use App\Models\KlienAr;
use Illuminate\Pagination\LengthAwarePaginator;

class KlienArRepository
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return KlienAr::with(['perusahaan', 'karyawanAr.perusahaan', 'resto.investor', 'createdBy', 'updatedBy'])
            ->when($filters['search'] ?? null, fn($q, $v) => $q->where(fn($q) => $q
                ->where('nama_klien', 'like', "%{$v}%")
                ->orWhere('kode_klien', 'like', "%{$v}%")
            ))
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('perusahaan_id', $v))
            ->when($filters['karyawan_ar_id'] ?? null, fn($q, $v) => $q->where('karyawan_ar_id', $v))
            ->when(isset($filters['status']), fn($q) => $q->where('status', $filters['status']))
            ->when($filters['segment'] ?? null, fn($q, $v) => $q->whereIn('tipe_klien', $this->resolveSegmentTypes($v)))
            ->latest()
            ->paginate($perPage);
    }

    public function getAll(array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        return KlienAr::with(['perusahaan', 'karyawanAr.perusahaan', 'resto.investor'])
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('perusahaan_id', $v))
            ->when($filters['karyawan_ar_id'] ?? null, fn($q, $v) => $q->where('karyawan_ar_id', $v))
            ->when($filters['segment'] ?? null, fn($q, $v) => $q->whereIn('tipe_klien', $this->resolveSegmentTypes($v)))
            ->where('status', true)
            ->orderBy('nama_klien')
            ->get();
    }

    private function resolveSegmentTypes(string $segment): array
    {
        return match(strtoupper($segment)) {
            'B2B'   => ['PT'],
            'B2C'   => ['RESTO'],
            default => ['PT', 'RESTO'],
        };
    }

    public function getAllForExport(array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        return KlienAr::with(['karyawanAr.perusahaan', 'resto.investor'])
            ->when($filters['search'] ?? null, fn($q, $v) => $q->where(fn($q) => $q
                ->where('nama_klien', 'like', "%{$v}%")
                ->orWhere('kode_klien', 'like', "%{$v}%")
            ))
            ->when($filters['karyawan_ar_id'] ?? null, fn($q, $v) => $q->where('karyawan_ar_id', $v))
            ->when(isset($filters['status']), fn($q) => $q->where('status', $filters['status']))
            ->when($filters['segment'] ?? null, fn($q, $v) => $q->whereIn('tipe_klien', $this->resolveSegmentTypes($v)))
            ->latest()
            ->get();
    }

    public function findById(int $id): ?KlienAr
    {
        return KlienAr::with(['perusahaan', 'karyawanAr.perusahaan', 'resto.investor', 'createdBy', 'updatedBy'])->find($id);
    }

    public function create(array $data): KlienAr
    {
        $klien = KlienAr::create($data);
        return $klien->load(['perusahaan', 'karyawanAr.perusahaan', 'resto.investor', 'createdBy', 'updatedBy']);
    }

    public function update(KlienAr $klien, array $data): KlienAr
    {
        $klien->update($data);
        return $klien->fresh(['perusahaan', 'karyawanAr.perusahaan', 'resto.investor', 'createdBy', 'updatedBy']);
    }

    public function delete(KlienAr $klien): bool
    {
        return (bool) $klien->forceDelete();
    }
}
