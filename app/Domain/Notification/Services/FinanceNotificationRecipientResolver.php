<?php

namespace App\Domain\Notification\Services;

use App\Models\KlienAr;
use App\Models\User;
use App\Models\VendorAp;
use App\Support\Enums\RoleEnum;
use Illuminate\Support\Collection;

/**
 * Menentukan siapa saja penerima notifikasi Finance berdasarkan role/scope yang
 * sudah ada di RoleHelper — bukan berdasarkan menu/visibility FE. AR murni tetap
 * scoped ke PIC/kliennya (karyawan_ar_id), AP murni tetap scoped ke PIC/vendornya
 * (karyawan_ap_id); Admin/Manager/Supervisor menerima event global/approval.
 */
class FinanceNotificationRecipientResolver
{
    /** Approver & pengawas global: ADMIN, MANAGER, SUPERVISOR. */
    public function approvers(): Collection
    {
        return $this->activeUsersWithRole([RoleEnum::ADMIN, RoleEnum::MANAGER, RoleEnum::SUPERVISOR]);
    }

    /** Approver tahap SPV khusus koreksi Ending Balance AR: ADMIN, SUPERVISOR (lihat RoleHelper::canApproveEndingBalanceSpv). */
    public function spvApprovers(): Collection
    {
        return $this->activeUsersWithRole([RoleEnum::ADMIN, RoleEnum::SUPERVISOR]);
    }

    /** PIC AR (role AR murni) yang ditugaskan ke klien tsb, via KlienAr::karyawan_ar_id. */
    public function arPicFor(?int $klienArId): Collection
    {
        if (!$klienArId) {
            return collect();
        }

        $karyawanId = KlienAr::withTrashed()->find($klienArId)?->karyawan_ar_id;

        return $this->usersForKaryawan($karyawanId, RoleEnum::AR);
    }

    /** PIC AP (role AP murni) yang ditugaskan ke vendor tsb, via VendorAp::karyawan_ap_id. */
    public function apPicFor(?int $vendorApId): Collection
    {
        if (!$vendorApId) {
            return collect();
        }

        $karyawanId = VendorAp::find($vendorApId)?->karyawan_ap_id;

        return $this->usersForKaryawan($karyawanId, RoleEnum::AP);
    }

    /** Satu user spesifik (mis. pemilik batch import), dibungkus Collection agar seragam. */
    public function user(?int $userId): Collection
    {
        if (!$userId) {
            return collect();
        }

        $user = User::where('status', true)->find($userId);

        return $user ? collect([$user]) : collect();
    }

    /**
     * Gabungkan beberapa Collection recipient, buang duplikat by id.
     *
     * @param  Collection[]  $groups
     */
    public function merge(array $groups): Collection
    {
        return collect($groups)->flatten()->unique('id')->values();
    }

    private function usersForKaryawan(?int $karyawanId, RoleEnum $role): Collection
    {
        if (!$karyawanId) {
            return collect();
        }

        return User::where('karyawan_id', $karyawanId)
            ->where('status', true)
            ->role($role->value)
            ->get();
    }

    /** @param  RoleEnum[]  $roles */
    private function activeUsersWithRole(array $roles): Collection
    {
        return User::where('status', true)
            ->role(array_map(fn(RoleEnum $r) => $r->value, $roles))
            ->get();
    }
}
