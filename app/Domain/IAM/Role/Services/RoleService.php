<?php

namespace App\Domain\IAM\Role\Services;

use App\Domain\IAM\Role\Repositories\RoleRepository;
use App\Models\Role;
use App\Support\Enums\RoleEnum;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class RoleService
{
    public function __construct(private readonly RoleRepository $repository) {}

    public function getAll(array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($filters);
    }

    public function getAllActive(): Collection
    {
        return $this->repository->all();
    }

    public function findOrFail(int $id): Role
    {
        $role = $this->repository->findById($id);
        abort_if(!$role, 404, 'Role tidak ditemukan');
        return $role;
    }

    public function create(array $data): Role
    {
        $data['guard_name'] = 'web';
        return $this->repository->create($data);
    }

    public function update(Role $role, array $data): Role
    {
        $protectedRoles = RoleEnum::values();
        if (in_array($role->name, $protectedRoles) && isset($data['name']) && $data['name'] !== $role->name) {
            abort(422, 'Nama kode role sistem tidak dapat diubah');
        }

        return $this->repository->update($role, $data);
    }

    public function delete(Role $role): void
    {
        abort_if(
            in_array($role->name, RoleEnum::values()),
            422,
            'Role sistem tidak dapat dihapus'
        );
        abort_if(
            $role->users()->count() > 0,
            422,
            'Role masih memiliki user aktif, tidak dapat dihapus'
        );

        $this->repository->delete($role);
    }
}
