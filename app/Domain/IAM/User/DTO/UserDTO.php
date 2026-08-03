<?php

namespace App\Domain\IAM\User\DTO;

class UserDTO
{
    public function __construct(
        public readonly string $username,
        public readonly ?string $email,
        public readonly ?string $password,
        public readonly ?int $karyawan_id,
        public readonly ?int $role_id,
        public readonly string $no_hp = '',
        public readonly bool $status = true,
        public readonly ?string $fonnte_token = null,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            username:     $data['username'],
            email:        $data['email'] ?? null,
            password:     $data['password'] ?? null,
            karyawan_id:  $data['karyawan_id'] ?? null,
            role_id:      $data['role_id'] ?? null,
            no_hp:        $data['no_hp'] ?? '',
            status:       isset($data['status']) ? (bool) $data['status'] : true,
            fonnte_token: $data['fonnte_token'] ?? null,
        );
    }
}
