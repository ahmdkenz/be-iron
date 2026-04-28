<?php

namespace App\Domain\IAM\User\Repositories;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class UserRepository
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return User::with(['karyawan', 'roles', 'createdBy', 'updatedBy'])
            ->when($filters['search'] ?? null, fn($q, $v) => $q->where(fn($q) => $q
                ->where('username', 'like', "%{$v}%")
                ->orWhere('email', 'like', "%{$v}%")
            ))
            ->when(isset($filters['status']), fn($q) => $q->where('status', $filters['status']))
            ->latest()
            ->paginate($perPage);
    }

    public function findById(int $id): ?User
    {
        return User::with(['karyawan', 'roles', 'createdBy', 'updatedBy'])->find($id);
    }

    public function create(array $data): User
    {
        $user = User::create($data);
        return $user->load(['karyawan', 'roles', 'createdBy', 'updatedBy']);
    }

    public function update(User $user, array $data): User
    {
        $user->update($data);
        return $user->fresh(['karyawan', 'roles', 'createdBy', 'updatedBy']);
    }

    public function delete(User $user): bool
    {
        $user->tokens()->delete();

        return (bool) $user->delete();
    }
}
