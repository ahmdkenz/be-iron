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

    public static function hasGlobalApAccess(?User $user): bool
    {
        return self::hasAnyRole($user, [RoleEnum::ADMIN, RoleEnum::MANAGER, RoleEnum::SUPERVISOR]);
    }

    public static function canViewVendorAp(?User $user): bool
    {
        return self::hasAnyRole($user, [
            RoleEnum::ADMIN,
            RoleEnum::MANAGER,
            RoleEnum::SUPERVISOR,
            RoleEnum::AP,
        ]);
    }

    public static function canOperateVendorAp(?User $user): bool
    {
        return self::hasAnyRole($user, [
            RoleEnum::ADMIN,
            RoleEnum::MANAGER,
            RoleEnum::SUPERVISOR,
            RoleEnum::AP,
        ]);
    }

    public static function canViewTagihanAp(?User $user): bool
    {
        return self::hasAnyRole($user, [
            RoleEnum::ADMIN,
            RoleEnum::MANAGER,
            RoleEnum::SUPERVISOR,
            RoleEnum::AP,
        ]);
    }

    public static function canOperateTagihanAp(?User $user): bool
    {
        return self::hasAnyRole($user, [RoleEnum::ADMIN, RoleEnum::AP]);
    }

    public static function canViewPembayaranAp(?User $user): bool
    {
        return self::hasAnyRole($user, [
            RoleEnum::ADMIN,
            RoleEnum::MANAGER,
            RoleEnum::SUPERVISOR,
            RoleEnum::AP,
        ]);
    }

    public static function canOperatePembayaranAp(?User $user): bool
    {
        return self::hasAnyRole($user, [RoleEnum::ADMIN, RoleEnum::AP]);
    }

    public static function canViewOpeningBalanceAp(?User $user): bool
    {
        return self::hasAnyRole($user, [
            RoleEnum::ADMIN,
            RoleEnum::MANAGER,
            RoleEnum::SUPERVISOR,
            RoleEnum::AP,
        ]);
    }

    public static function canOperateOpeningBalanceAp(?User $user): bool
    {
        return self::hasAnyRole($user, [
            RoleEnum::ADMIN,
            RoleEnum::MANAGER,
            RoleEnum::SUPERVISOR,
            RoleEnum::AP,
        ]);
    }

    public static function canApproveOpeningBalanceAp(?User $user): bool
    {
        return self::hasAnyRole($user, [RoleEnum::MANAGER, RoleEnum::SUPERVISOR]);
    }

    // Staging import SHZ360 — ADMIN/MANAGER/SUPERVISOR punya akses global,
    // dan role AP diberi akses penuh yang sama (lihat, petakan vendor, konversi, dll).
    public static function canViewApShz360Import(?User $user): bool
    {
        return self::hasGlobalApAccess($user) || self::hasRole($user, RoleEnum::AP);
    }

    public static function canOperateApShz360Import(?User $user): bool
    {
        return self::hasGlobalApAccess($user) || self::hasRole($user, RoleEnum::AP);
    }

    public static function canAccessApDashboard(?User $user): bool
    {
        return self::hasRole($user, RoleEnum::AP) || self::hasGlobalApAccess($user);
    }

    // Returns true when the user is a pure PIC AP (AP role but not Admin/Manager/Supervisor).
    // Used to restrict data visibility to only their assigned vendors.
    public static function isApStaff(?User $user): bool
    {
        return self::hasAnyRole($user, [RoleEnum::AP])
            && !self::hasAnyRole($user, [
                RoleEnum::ADMIN,
                RoleEnum::MANAGER,
                RoleEnum::SUPERVISOR,
            ]);
    }

    // Mengembalikan karyawan_ap_id yang membatasi user PIC AP (AP murni),
    // atau null jika user punya akses AP tanpa batas (Admin/Manager/Supervisor).
    public static function picApKaryawanIdFor(?User $user): ?int
    {
        return self::isApStaff($user) ? $user->karyawan?->id : null;
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
