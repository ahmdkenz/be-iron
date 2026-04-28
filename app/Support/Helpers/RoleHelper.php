<?php

namespace App\Support\Helpers;

use App\Models\User;
use App\Support\Enums\RoleEnum;

class RoleHelper
{
    public static function hasRole(?User $user, RoleEnum|string $role): bool
    {
        if (!$user) {
            return false;
        }

        $roleValue = $role instanceof RoleEnum ? $role->value : $role;

        return $user->hasRole($roleValue);
    }

    public static function hasAnyRole(?User $user, array $roles): bool
    {
        if (!$user) {
            return false;
        }

        $roleValues = array_map(
            fn(RoleEnum|string $role) => $role instanceof RoleEnum ? $role->value : $role,
            $roles
        );

        return $user->hasAnyRole($roleValues);
    }

    public static function hasGlobalFinanceAccess(?User $user): bool
    {
        return self::hasAnyRole($user, [RoleEnum::ADMIN, RoleEnum::DIREKTUR]);
    }

    public static function isArOnly(?User $user): bool
    {
        return self::hasRole($user, RoleEnum::AR)
            && !self::hasAnyRole($user, [
                RoleEnum::ADMIN,
                RoleEnum::DIREKTUR,
                RoleEnum::MANAGER,
                RoleEnum::SUPERVISOR,
            ]);
    }

    public static function isDirectorOnly(?User $user): bool
    {
        return self::hasRole($user, RoleEnum::DIREKTUR)
            && !self::hasAnyRole($user, [
                RoleEnum::ADMIN,
                RoleEnum::MANAGER,
                RoleEnum::SUPERVISOR,
                RoleEnum::AR,
                RoleEnum::AP,
            ]);
    }

    public static function canViewOpeningBalance(?User $user): bool
    {
        return self::hasAnyRole($user, [
            RoleEnum::ADMIN,
            RoleEnum::DIREKTUR,
            RoleEnum::MANAGER,
            RoleEnum::SUPERVISOR,
            RoleEnum::AR,
        ]);
    }

    public static function canOperateOpeningBalance(?User $user): bool
    {
        return self::hasAnyRole($user, [
            RoleEnum::MANAGER,
            RoleEnum::SUPERVISOR,
            RoleEnum::AR,
        ]);
    }

    public static function canApproveOpeningBalance(?User $user): bool
    {
        return self::hasRole($user, RoleEnum::DIREKTUR);
    }

    public static function canAccessArDashboard(?User $user): bool
    {
        return self::hasRole($user, RoleEnum::AR);
    }
}
