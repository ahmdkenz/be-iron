<?php

namespace App\Support\Helpers;

use App\Models\User;

class ApFilterScope
{
    /**
     * Terapkan filter scoping untuk AP data berdasarkan role user.
     *
     * - Non-global user (bukan ADMIN/MANAGER/SUPERVISOR): scope by perusahaan
     * - AP-only user: scope by vendor yang di-assign ke mereka (override perusahaan_id)
     */
    public static function apply(array &$filters, User $user): void
    {
        $user->loadMissing('karyawan');

        if ($user->karyawan && !RoleHelper::hasGlobalApAccess($user)) {
            $filters['perusahaan_id'] = $user->karyawan->perusahaan_id;
        }

        if (RoleHelper::isApStaff($user) && $user->karyawan) {
            $filters['pic_ap_karyawan_id'] = $user->karyawan->id;
            unset($filters['karyawan_id']);
            unset($filters['perusahaan_id']);
        }
    }
}
