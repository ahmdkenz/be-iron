<?php

namespace App\Domain\IAM\Role\Repositories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class RoleRepository
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Role::withCount('users')
            ->with(['createdBy', 'updatedBy'])
            ->when($filters['search'] ?? null, fn($q, $v) => $q->where(fn($q) => $q
                ->where('name', 'like', "%{$v}%")
                ->orWhere('nama_role', 'like', "%{$v}%")
            ))
            ->when(isset($filters['status']), fn($q) => $q->where('status', $filters['status']))
            ->latest()
            ->paginate($perPage);
    }

    public function all(): Collection
    {
        return Role::where('status', true)->orderBy('name')->get();
    }

    public function findById(int $id): ?Role
    {
        return Role::withCount('users')->with(['createdBy', 'updatedBy'])->find($id);
    }

    public function create(array $data): Role
    {
        return Role::create($data);
    }

    public function update(Role $role, array $data): Role
    {
        $role->update($data);
        return $role->fresh();
    }

    public function delete(Role $role): bool
    {
        return (bool) $role->delete();
    }
}
