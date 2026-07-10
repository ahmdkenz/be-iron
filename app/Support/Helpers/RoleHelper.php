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
        return self::hasAnyRole($user, [RoleEnum::ADMIN]);
    }

    public static function hasGlobalArAccess(?User $user): bool
    {
        return self::hasAnyRole($user, [RoleEnum::ADMIN, RoleEnum::MANAGER, RoleEnum::SUPERVISOR]);
    }

    public static function hasGlobalDashboardAccess(?User $user): bool
    {
        return self::hasAnyRole($user, [
            RoleEnum::ADMIN,
            RoleEnum::MANAGER,
            RoleEnum::SUPERVISOR,
        ]);
    }

    public static function isArOnly(?User $user): bool
    {
        return self::hasRole($user, RoleEnum::AR)
            && !self::hasAnyRole($user, [
                RoleEnum::ADMIN,
                RoleEnum::MANAGER,
                RoleEnum::SUPERVISOR,
            ]);
    }

    public static function canViewOpeningBalance(?User $user): bool
    {
        return self::hasAnyRole($user, [
            RoleEnum::ADMIN,
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
        return self::hasAnyRole($user, [RoleEnum::MANAGER, RoleEnum::SUPERVISOR]);
    }

    public static function canViewEndingBalance(?User $user): bool
    {
        return self::hasAnyRole($user, [
            RoleEnum::ADMIN,
            RoleEnum::MANAGER,
            RoleEnum::SUPERVISOR,
            RoleEnum::AR,
        ]);
    }

    public static function canOperateEndingBalance(?User $user): bool
    {
        return self::hasAnyRole($user, [
            RoleEnum::ADMIN,
            RoleEnum::MANAGER,
            RoleEnum::SUPERVISOR,
            RoleEnum::AR,
        ]);
    }

    public static function canLockEndingBalance(?User $user): bool
    {
        return self::hasAnyRole($user, [
            RoleEnum::ADMIN,
            RoleEnum::MANAGER,
            RoleEnum::SUPERVISOR,
        ]);
    }

    public static function canApproveEndingBalanceSpv(?User $user): bool
    {
        return self::hasAnyRole($user, [RoleEnum::ADMIN, RoleEnum::SUPERVISOR]);
    }

    /**
     * Approver final koreksi Ending Balance (satu tahap).
     * Supervisor dibuat setara Manager, Admin tetap mengikuti behavior existing.
     */
    public static function canApproveEndingBalanceManager(?User $user): bool
    {
        return self::hasAnyRole($user, [RoleEnum::ADMIN, RoleEnum::MANAGER, RoleEnum::SUPERVISOR]);
    }

    public static function canAccessArDashboard(?User $user): bool
    {
        return self::hasRole($user, RoleEnum::AR);
    }

    // Returns true when the user is a pure PIC AR (AR role but not Admin/Manager/Supervisor/Direktur).
    // Used to restrict data visibility to only their assigned clients.
    public static function isArStaff(?User $user): bool
    {
        return self::hasAnyRole($user, [RoleEnum::AR])
            && !self::hasAnyRole($user, [
                RoleEnum::ADMIN,
                RoleEnum::MANAGER,
                RoleEnum::SUPERVISOR,
            ]);
    }

    // Mengembalikan karyawan_ar_id yang membatasi user PIC AR (AR murni),
    // atau null jika user punya akses AR tanpa batas (Admin/Manager/Supervisor).
    public static function picArKaryawanIdFor(?User $user): ?int
    {
        return self::isArStaff($user) ? $user->karyawan?->id : null;
    }

    // Returns true when the user may manage a record owned by $matchedByUserId:
    // Admin/Manager/Supervisor may always manage it; otherwise only the user who owns it.
    public static function canManageMatchedRecord(?User $user, ?int $matchedByUserId): bool
    {
        if (self::hasGlobalArAccess($user)) {
            return true;
        }

        return $user !== null && $matchedByUserId !== null && (int) $user->id === (int) $matchedByUserId;
    }
}
