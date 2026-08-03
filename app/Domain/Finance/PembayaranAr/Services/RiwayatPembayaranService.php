<?php

namespace App\Domain\Finance\PembayaranAr\Services;

use App\Models\PembayaranAr;
use App\Models\User;
use App\Support\Helpers\RoleHelper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Query bersama untuk laporan "Riwayat Pembayaran" (daftar pembayaran AR).
 *
 * Dipakai oleh halaman list (paginated) DAN oleh Export Data, supaya filter dan
 * role scoping-nya persis sama — export tidak boleh mengambil data lebih luas
 * daripada yang boleh dilihat user di layar.
 */
class RiwayatPembayaranService
{
    private const EAGER_LOADS = [
        'invoice.klienAr',
        'invoice.perusahaan',
        'invoice.karyawan',
        'items.invoice.klienAr',
        'items.invoice.perusahaan',
        'items.invoice.karyawan',
        'createdBy.karyawan',
        'bankStatementDetail',
        'sumberPembayaran.bankStatementDetail',
    ];

    /**
     * Multi Payment (header invoice_id NULL, alokasi lewat tb_pembayaran_ar_items)
     * sebelumnya selalu hilang dari laporan ini karena setiap filter/scoping di
     * bawah cuma whereHas('invoice', ...) — mustahil match kalau invoice_id null.
     * Sekarang tiap klausa dicek lewat invoice tunggal ATAU salah satu item.
     */
    public function query(array $filters, User $user): Builder
    {
        $user->loadMissing('karyawan');

        $segmentTypes = match (strtoupper((string) ($filters['segment'] ?? ''))) {
            'B2B'   => ['PT'],
            'B2C'   => ['RESTO'],
            default => null,
        };

        $query = PembayaranAr::query()
            ->when($filters['klien_ar_id'] ?? null, fn(Builder $q, $v) =>
                $q->where(fn($q) => $q
                    ->whereHas('invoice', fn($q) => $q->where('klien_ar_id', $v))
                    ->orWhereHas('items', fn($q) => $q->where('klien_ar_id', $v))
                )
            )
            ->when($filters['karyawan_id'] ?? null, fn(Builder $q, $v) =>
                $q->where(fn($q) => $q
                    ->whereHas('invoice', fn($q) => $q->where('karyawan_id', $v))
                    ->orWhereHas('items.invoice', fn($q) => $q->where('karyawan_id', $v))
                )
            )
            ->when($filters['metode_pembayaran'] ?? null, fn(Builder $q, $v) => $q->where('metode_pembayaran', $v))
            ->when($filters['tanggal_dari'] ?? null, fn(Builder $q, $v) => $q->whereDate('tanggal_pembayaran', '>=', $v))
            ->when($filters['tanggal_sampai'] ?? null, fn(Builder $q, $v) => $q->whereDate('tanggal_pembayaran', '<=', $v))
            ->when($segmentTypes, fn(Builder $q) =>
                $q->where(fn($q) => $q
                    ->whereHas('invoice.klienAr', fn($q) => $q->whereIn('tipe_klien', $segmentTypes))
                    ->orWhereHas('items.invoice.klienAr', fn($q) => $q->whereIn('tipe_klien', $segmentTypes))
                )
            );

        if ($user->karyawan && !RoleHelper::hasGlobalArAccess($user)) {
            $query->where(fn($q) => $q
                ->whereHas('invoice', fn($q) => $q->where('perusahaan_id', $user->karyawan->perusahaan_id))
                ->orWhereHas('items.invoice', fn($q) => $q->where('perusahaan_id', $user->karyawan->perusahaan_id))
            );
        }

        if (RoleHelper::isArOnly($user) && $user->karyawan) {
            $query->where(fn($q) => $q
                ->whereHas('invoice.klienAr', fn($q) => $q->where('karyawan_ar_id', $user->karyawan->id))
                ->orWhereHas('items.invoice.klienAr', fn($q) => $q->where('karyawan_ar_id', $user->karyawan->id))
            );
        }

        return $query;
    }

    public function paginate(array $filters, User $user, int $perPage): LengthAwarePaginator
    {
        return $this->query($filters, $user)
            ->with(self::EAGER_LOADS)
            ->latest('tanggal_pembayaran')
            ->paginate($perPage);
    }

    /** Seluruh baris yang cocok dengan filter — tanpa paginasi (dipakai export). */
    public function getAll(array $filters, User $user): Collection
    {
        return $this->query($filters, $user)
            ->with(self::EAGER_LOADS)
            ->latest('tanggal_pembayaran')
            ->get();
    }

    public function totalJumlah(array $filters, User $user): float
    {
        return (float) $this->query($filters, $user)->sum('jumlah_pembayaran');
    }
}
