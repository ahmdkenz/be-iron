<?php

namespace App\Support\Enums;

enum RoleEnum: string
{
    case ADMIN      = 'ADMIN';
    case DIREKTUR   = 'DIREKTUR';
    case MANAGER    = 'MANAGER';
    case SUPERVISOR = 'SUPERVISOR';
    case AR         = 'AR';
    case AP         = 'AP';

    public function label(): string
    {
        return match($this) {
            self::ADMIN      => 'Admin',
            self::DIREKTUR   => 'Direktur',
            self::MANAGER    => 'Manager',
            self::SUPERVISOR => 'Supervisor',
            self::AR         => 'AR',
            self::AP         => 'AP',
        };
    }

    public static function values(): array
    {
        return array_map(fn(self $role) => $role->value, self::cases());
    }
}
