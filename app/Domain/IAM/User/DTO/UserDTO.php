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
        public readonly ?string $smtp_host = null,
        public readonly ?int $smtp_port = null,
        public readonly ?string $smtp_username = null,
        public readonly ?string $smtp_password = null,
        public readonly ?string $smtp_encryption = null,
        public readonly ?string $smtp_from_email = null,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            username:        $data['username'],
            email:           $data['email'] ?? null,
            password:        $data['password'] ?? null,
            karyawan_id:     $data['karyawan_id'] ?? null,
            role_id:         $data['role_id'] ?? null,
            no_hp:           $data['no_hp'] ?? '',
            status:          isset($data['status']) ? (bool) $data['status'] : true,
            smtp_host:       $data['smtp_host'] ?? null,
            smtp_port:       isset($data['smtp_port']) ? (int) $data['smtp_port'] : null,
            smtp_username:   $data['smtp_username'] ?? null,
            smtp_password:   $data['smtp_password'] ?? null,
            smtp_encryption: $data['smtp_encryption'] ?? null,
            smtp_from_email: $data['smtp_from_email'] ?? null,
        );
    }
}
