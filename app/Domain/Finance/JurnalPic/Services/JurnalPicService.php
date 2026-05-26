<?php

namespace App\Domain\Finance\JurnalPic\Services;

use App\Models\PembayaranAr;
use App\Support\Helpers\RoleHelper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class JurnalPicService
{
    private function buildFilteredQuery(array $filters): Builder
    {
        $query = PembayaranAr::query()
            ->whereHas('invoice')
            ->when(
                $filters['no_referensi'] ?? null,
                fn(Builder $q, string $v) => $q->where('no_referensi', 'like', "%{$v}%")
            )
            ->when(
                $filters['karyawan_id'] ?? null,
                fn(Builder $q, int $v) => $q->whereHas('invoice', fn($q) => $q->where('karyawan_id', $v))
            )
            ->when(
                $filters['metode_pembayaran'] ?? null,
                fn(Builder $q, string $v) => $q->where('metode_pembayaran', $v)
            )
            ->when(
                $filters['tanggal_dari'] ?? null,
                fn(Builder $q, string $v) => $q->whereDate('tanggal_pembayaran', '>=', $v)
            )
            ->when(
                $filters['tanggal_sampai'] ?? null,
                fn(Builder $q, string $v) => $q->whereDate('tanggal_pembayaran', '<=', $v)
            )
            ->when(
                $filters['status_rekonsiliasi'] ?? null,
                fn(Builder $q, string $v) => $q->where(function (Builder $q) use ($v) {
                    $q->whereHas('bankStatementDetail', fn($q) => $q->where('status_cocok', $v))
                      ->orWhereHas('sumberPembayaran.bankStatementDetail', fn($q) => $q->where('status_cocok', $v));
                })
            );

        if (isset($filters['_user'])) {
            $user = $filters['_user'];
            if ($user->karyawan && !RoleHelper::hasGlobalFinanceAccess($user)) {
                $query->whereHas('invoice', fn($q) =>
                    $q->where('perusahaan_id', $user->karyawan->perusahaan_id)
                );
            }
        }

        return $query;
    }

    public function getJurnal(array $filters): LengthAwarePaginator
    {
        return $this->buildFilteredQuery($filters)
            ->with([
                'invoice.karyawan',
                'invoice.klienAr',
                'invoice.perusahaan',
                'createdBy',
                'bankStatementDetail',
                'sumberPembayaran.bankStatementDetail',
            ])
            ->latest('tanggal_pembayaran')
            ->paginate($filters['per_page'] ?? 20);
    }

    public function getJurnalAll(array $filters): Collection
    {
        return $this->buildFilteredQuery($filters)
            ->with([
                'invoice.karyawan',
                'invoice.klienAr',
                'invoice.perusahaan',
                'createdBy',
                'bankStatementDetail',
                'sumberPembayaran.bankStatementDetail',
            ])
            ->latest('tanggal_pembayaran')
            ->get();
    }

    public function getSummary(array $filters): array
    {
        $base    = $this->buildFilteredQuery($filters);
        $total   = (clone $base)->count();
        $nominal = (clone $base)->sum('jumlah_pembayaran');
        $matched = (clone $base)->where(function (Builder $q) {
            $q->whereHas('bankStatementDetail', fn($q) => $q->where('status_cocok', 'MATCHED'))
              ->orWhereHas('sumberPembayaran.bankStatementDetail', fn($q) => $q->where('status_cocok', 'MATCHED'));
        })->count();

        return [
            'total_transaksi'   => $total,
            'total_nominal'     => (float) $nominal,
            'total_matched'     => $matched,
            'total_belum_cocok' => $total - $matched,
        ];
    }
}
