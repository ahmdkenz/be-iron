<?php

namespace App\Domain\Finance\VendorAp\Services;

use App\Domain\Finance\VendorAp\DTO\VendorApDTO;
use App\Domain\Finance\VendorAp\Repositories\VendorApRepository;
use App\Models\VendorAp;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class VendorApService
{
    public function __construct(private readonly VendorApRepository $repository) {}

    public function generateKodeVendor(): string
    {
        $count = VendorAp::withTrashed()
            ->where('kode_vendor', 'like', 'VN%')
            ->count();
        $seq = str_pad($count + 1, 3, '0', STR_PAD_LEFT);

        return "VN{$seq}";
    }

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
        return $this->repository->create([
            'kode_vendor'      => $this->generateKodeVendor(),
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
        return $this->repository->update($vendor, [
            // Kode vendor historis tetap stabil, tidak berubah saat edit.
            'kode_vendor'      => $vendor->kode_vendor,
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

    public function delete(VendorAp $vendor): void
    {
        abort_if(
            $vendor->tagihanAp()->exists(),
            422,
            'Vendor tidak dapat dihapus karena memiliki data tagihan'
        );
        $this->repository->delete($vendor);
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
