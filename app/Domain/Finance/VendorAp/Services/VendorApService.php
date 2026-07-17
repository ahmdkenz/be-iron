<?php

namespace App\Domain\Finance\VendorAp\Services;

use App\Domain\Finance\ApShz360Sync\Support\VendorNameMatcher;
use App\Domain\Finance\VendorAp\DTO\VendorApDTO;
use App\Domain\Finance\VendorAp\Repositories\VendorApRepository;
use App\Models\VendorAp;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VendorApService
{
    public function __construct(private readonly VendorApRepository $repository) {}

    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($filters);
    }

    public function getAll(array $filters = []): Collection
    {
        return $this->repository->getAll($filters);
    }

    public function findOrFail(int $id): VendorAp
    {
        $vendor = $this->repository->findById($id);
        abort_if(!$vendor, 404, 'Vendor tidak ditemukan');
        return $vendor;
    }

    public function create(VendorApDTO $dto): VendorAp
    {
        $this->assertNoDuplicate($dto->kode_vendor, $dto->nama_vendor);

        return $this->repository->create([
            'kode_vendor'      => trim($dto->kode_vendor),
            'nama_vendor'      => $dto->nama_vendor,
            'no_npwp'          => $dto->no_npwp,
            'status_pkp'       => $dto->status_pkp,
            'bank_nama'        => $dto->bank_nama,
            'bank_no_rekening' => $dto->bank_no_rekening,
            'bank_atas_nama'   => $dto->bank_atas_nama,
            'karyawan_ap_id'   => $dto->karyawan_ap_id,
            'status'           => $dto->status,
            'created_by'       => auth()->id(),
        ]);
    }

    public function update(VendorAp $vendor, VendorApDTO $dto): VendorAp
    {
        $this->assertNoDuplicate($dto->kode_vendor, $dto->nama_vendor, $vendor->id);

        return $this->repository->update($vendor, [
            'kode_vendor'      => trim($dto->kode_vendor),
            'nama_vendor'      => $dto->nama_vendor,
            'no_npwp'          => $dto->no_npwp,
            'status_pkp'       => $dto->status_pkp,
            'bank_nama'        => $dto->bank_nama,
            'bank_no_rekening' => $dto->bank_no_rekening,
            'bank_atas_nama'   => $dto->bank_atas_nama,
            'karyawan_ap_id'   => $dto->karyawan_ap_id,
            'status'           => $dto->status,
            'updated_by'       => auth()->id(),
        ]);
    }

    /**
     * Cek Kode Supplier & nama vendor (ternormalisasi) supaya vendor yang sama
     * tidak tercatat dobel — lihat kasus "Tambar Bawang" yang dulu punya 2 kode.
     */
    private function assertNoDuplicate(string $kodeVendor, string $namaVendor, ?int $ignoreId = null): void
    {
        $kodeQuery = VendorAp::withTrashed()->where('kode_vendor', trim($kodeVendor));
        if ($ignoreId) {
            $kodeQuery->where('id', '!=', $ignoreId);
        }
        if ($kodeQuery->exists()) {
            throw ValidationException::withMessages([
                'kode_vendor' => 'Kode Supplier ini sudah dipakai vendor lain.',
            ]);
        }

        $normalizedName = VendorNameMatcher::normalize($namaVendor);
        $namaQuery = VendorAp::withTrashed()->whereRaw('UPPER(TRIM(nama_vendor)) = ?', [$normalizedName]);
        if ($ignoreId) {
            $namaQuery->where('id', '!=', $ignoreId);
        }
        $existing = $namaQuery->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'nama_vendor' => "Nama vendor ini sudah terdaftar (Kode Supplier: {$existing->kode_vendor}).",
            ]);
        }
    }

    public function delete(VendorAp $vendor): void
    {
        abort_if(
            $vendor->tagihanAp()->exists(),
            422,
            'Vendor tidak dapat dihapus karena memiliki data tagihan'
        );
        DB::transaction(fn () => $this->repository->delete($vendor));
    }

    public function bulkDelete(array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $id) {
            try {
                $vendor = $this->repository->findById((int) $id);
                if (!$vendor) {
                    continue;
                }
                $this->delete($vendor);
                $deleted++;
            } catch (\Throwable) {
                // skip jika gagal (memiliki tagihan terkait, dll)
            }
        }
        return $deleted;
    }
}
