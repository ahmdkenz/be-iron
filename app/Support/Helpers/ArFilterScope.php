<?php

namespace App\Support\Helpers;

use App\Models\User;

class ArFilterScope
{
    /**
     * Terapkan filter scoping untuk AR data berdasarkan role user.
     *
     * - Non-global user (bukan ADMIN/DIREKTUR/MANAGER/SUPERVISOR): scope by perusahaan
     * - AR-only user: scope by klien yang di-assign ke mereka (override perusahaan_id)
     */
    public static function apply(array &$filters, User $user): void
    {
        $user->loadMissing('karyawan');

        if ($user->karyawan && !RoleHelper::hasGlobalArAccess($user)) {
            $filters['perusahaan_id'] = $user->karyawan->perusahaan_id;
        }

        if (RoleHelper::isArOnly($user) && $user->karyawan) {
            $filters['pic_ar_karyawan_id'] = $user->karyawan->id;
            unset($filters['karyawan_id']);
            unset($filters['perusahaan_id']);
        }
    }
}
