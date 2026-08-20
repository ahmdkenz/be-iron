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
            'username'        => $dto->username,
            'email'           => $dto->email,
            'password'        => Hash::make($dto->password),
            'karyawan_id'     => $dto->karyawan_id,
            'no_hp'           => $dto->no_hp,
            'status'          => $dto->status,
            'smtp_host'       => $dto->smtp_host,
            'smtp_port'       => $dto->smtp_port,
            'smtp_username'   => $dto->smtp_username,
            'smtp_password'   => $dto->smtp_password,
            'smtp_encryption' => $dto->smtp_encryption,
            'smtp_from_email' => $dto->smtp_from_email,
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
            'karyawan_id' => $dto->karyawan_id,
            'no_hp'       => $dto->no_hp,
            'status'      => $dto->status,
        ], fn($v) => $v !== null);

        $data['email']           = $dto->email;
        $data['smtp_host']       = $dto->smtp_host;
        $data['smtp_port']       = $dto->smtp_port;
        $data['smtp_username']   = $dto->smtp_username;
        $data['smtp_encryption'] = $dto->smtp_encryption;
        $data['smtp_from_email'] = $dto->smtp_from_email;

        if ($dto->smtp_password) {
            $data['smtp_password'] = $dto->smtp_password;
        }

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

    public function bulkDelete(array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $id) {
            try {
                $user = $this->repository->findById((int) $id);
                if (!$user) {
                    continue;
                }
                $this->delete($user);
                $deleted++;
            } catch (\Throwable) {
                // skip jika gagal (satu-satunya admin, constraint, dll)
            }
        }
        return $deleted;
    }
}
