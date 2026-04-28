<?php

namespace App\Domain\IAM\User\Services;

use App\Domain\IAM\User\DTO\UserDTO;
use App\Domain\IAM\User\Repositories\UserRepository;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function __construct(private readonly UserRepository $repository) {}

    public function getAll(array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($filters);
    }

    public function findOrFail(int $id): User
    {
        $user = $this->repository->findById($id);
        abort_if(!$user, 404, 'User tidak ditemukan');
        return $user;
    }

    public function create(UserDTO $dto): User
    {
        $data = [
            'username'    => $dto->username,
            'email'       => $dto->email,
            'password'    => Hash::make($dto->password),
            'karyawan_id' => $dto->karyawan_id,
            'no_hp'       => $dto->no_hp,
            'status'      => $dto->status,
        ];

        $user = $this->repository->create($data);

        if ($dto->role_id) {
            $user->roles()->sync([$dto->role_id]);
        }

        return $user->load(['karyawan', 'roles']);
    }

    public function update(User $user, UserDTO $dto): User
    {
        $data = array_filter([
            'username'    => $dto->username,
            'email'       => $dto->email,
            'karyawan_id' => $dto->karyawan_id,
            'no_hp'       => $dto->no_hp,
            'status'      => $dto->status,
        ], fn($v) => $v !== null);

        if ($dto->password) {
            $data['password'] = Hash::make($dto->password);
        }

        $user = $this->repository->update($user, $data);

        if ($dto->role_id) {
            $user->roles()->sync([$dto->role_id]);
        }

        return $user;
    }

    public function delete(User $user): void
    {
        abort_if(
            $user->hasRole('ADMIN') && User::role('ADMIN')->count() <= 1,
            422,
            'Tidak bisa menghapus satu-satunya Admin'
        );

        $this->repository->delete($user);
    }
}
