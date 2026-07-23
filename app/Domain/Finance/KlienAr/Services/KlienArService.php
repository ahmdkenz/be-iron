<?php

namespace App\Domain\Finance\KlienAr\Services;

use App\Domain\Finance\KlienAr\DTO\KlienArDTO;
use App\Domain\Finance\KlienAr\Repositories\KlienArRepository;
use App\Models\Karyawan;
use App\Models\KlienAr;
use App\Models\Resto;
use Illuminate\Pagination\LengthAwarePaginator;

class KlienArService
{
    public function __construct(private readonly KlienArRepository $repository) {}

    public function generateKodeKlien(?string $tipeKlien = null): string
    {
        $count = KlienAr::withTrashed()
            ->where('kode_klien', 'like', 'CL%')
            ->count();
        $seq = str_pad($count + 1, 3, '0', STR_PAD_LEFT);

        return "CL{$seq}";
    }

    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($filters);
    }

    public function getAll(array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        return $this->repository->getAll($filters);
    }

    public function getAllForExport(array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        return $this->repository->getAllForExport($filters);
    }

    public function findOrFail(int $id): KlienAr
    {
        $klien = $this->repository->findById($id);
        abort_if(!$klien, 404, 'Client tidak ditemukan');
        return $klien;
    }

    public function create(KlienArDTO $dto): KlienAr
    {
        $perusahaanId = $dto->perusahaan_id
            ?? ($dto->tipe_klien === 'RESTO' && $dto->resto_id
                ? Resto::find($dto->resto_id)?->perusahaan_id
                : null)
            ?? Karyawan::find($dto->karyawan_ar_id)?->perusahaan_id;

        return $this->repository->create([
            'kode_klien'    => $this->generateKodeKlien(),
            'nama_klien'    => $dto->nama_klien,
            'tipe_klien'    => $dto->tipe_klien,
            'no_npwp'       => $dto->no_npwp,
            'no_wa'         => $dto->no_wa,
            'perusahaan_id' => $perusahaanId,
            'karyawan_ar_id'=> $this->resolveKaryawanArId($dto),
            'resto_id'      => $dto->resto_id,
            'status'        => $dto->status,
            'created_by'    => auth()->id(),
        ]);
    }

    public function update(KlienAr $klien, KlienArDTO $dto): KlienAr
    {
        $perusahaanId = $dto->perusahaan_id
            ?? ($dto->tipe_klien === 'RESTO' && $dto->resto_id
                ? Resto::find($dto->resto_id)?->perusahaan_id
                : null)
            ?? $klien->perusahaan_id
            ?? Karyawan::find($dto->karyawan_ar_id)?->perusahaan_id;

        return $this->repository->update($klien, [
            // Keep historical client codes stable for existing records.
            'kode_klien'    => $klien->kode_klien,
            'nama_klien'    => $dto->nama_klien,
            'tipe_klien'    => $dto->tipe_klien,
            'no_npwp'       => $dto->no_npwp,
            'no_wa'         => $dto->no_wa,
            'perusahaan_id' => $perusahaanId,
            'karyawan_ar_id'=> $this->resolveKaryawanArId($dto),
            'resto_id'      => $dto->resto_id,
            'status'        => $dto->status,
            'updated_by'    => auth()->id(),
        ]);
    }

    // PIC Resto (karyawan_id) adalah sumber kebenaran untuk PIC AR Client tipe RESTO —
    // mencegah karyawan_ar_id disimpangkan lewat form/API KlienAr sendiri.
    private function resolveKaryawanArId(KlienArDTO $dto): int
    {
        if ($dto->tipe_klien === 'RESTO' && $dto->resto_id) {
            $restoKaryawanId = Resto::find($dto->resto_id)?->karyawan_id;
            if ($restoKaryawanId) {
                return $restoKaryawanId;
            }
        }

        return $dto->karyawan_ar_id;
    }

    public function delete(KlienAr $klien): void
    {
        abort_if(
            $klien->invoices()->exists(),
            422,
            'Client tidak dapat dihapus karena memiliki data invoice'
        );
        $this->repository->delete($klien);
    }

    public function bulkDelete(array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $id) {
            try {
                $klien = $this->repository->findById((int) $id);
                if (!$klien) {
                    continue;
                }
                $this->delete($klien);
                $deleted++;
            } catch (\Throwable) {
                // skip jika gagal (memiliki invoice terkait, dll)
            }
        }
        return $deleted;
    }


}
