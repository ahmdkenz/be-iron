<?php

namespace App\Domain\Master\Investor\Repositories;

use App\Models\Investor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class InvestorRepository
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Investor::with(['createdBy', 'updatedBy'])
            ->when($filters['search'] ?? null, fn($q, $v) => $q->where(fn($q) => $q
                ->where('nama_investor', 'like', "%{$v}%")
                ->orWhere('pengelola', 'like', "%{$v}%")
            ))
            ->when(isset($filters['status']), fn($q) => $q->where('status', $filters['status']))
            ->latest()
            ->paginate($perPage);
    }

    public function all(): Collection
    {
        return Investor::where('status', true)
            ->orderBy('nama_investor')
            ->get(['id', 'nama_investor', 'pengelola']);
    }

    public function findById(int $id): ?Investor
    {
        return Investor::with(['createdBy', 'updatedBy'])->find($id);
    }

    public function create(array $data): Investor
    {
        $investor = Investor::create($data);
        return $investor->load(['createdBy', 'updatedBy']);
    }

    public function update(Investor $investor, array $data): Investor
    {
        $investor->update($data);
        return $investor->fresh(['createdBy', 'updatedBy']);
    }

    public function delete(Investor $investor): bool
    {
        return (bool) $investor->forceDelete();
    }
}
