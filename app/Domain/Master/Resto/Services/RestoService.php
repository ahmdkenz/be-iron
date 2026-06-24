<?php

namespace App\Domain\Master\Resto\Services;

use App\Domain\Master\Resto\DTO\RestoDTO;
use App\Domain\Master\Resto\Repositories\RestoRepository;
use App\Models\Resto;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class RestoService
{
    public function __construct(private readonly RestoRepository $repository) {}

    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->paginate($filters, $perPage);
    }

    public function getAllForExport(array $filters = []): EloquentCollection
    {
        return $this->repository->getAllForExport($filters);
    }

    public function findOrFail(int $id): Resto
    {
        $resto = $this->repository->findById($id);
        abort_if(!$resto, 404, 'Resto tidak ditemukan');
        return $resto;
    }

    public function create(RestoDTO $dto): Resto
    {
        return $this->repository->create([
            'kode_resto'    => $dto->kode_resto,
            'nama_resto'    => $dto->nama_resto,
            'perusahaan_id' => $dto->perusahaan_id,
            'brand_id'      => $dto->brand_id,
            'investor_id'   => $dto->investor_id,
            'karyawan_id'     => $dto->karyawan_id,
            'supervisor'      => $dto->supervisor,
            'no_hp_supervisor' => $dto->no_hp_supervisor,
            'stokis'          => $dto->stokis,
            'area'            => $dto->area,
            'kota'            => $dto->kota,
            'alamat'          => $dto->alamat,
            'no_telp'         => $dto->no_telp,
            'tgl_aktif'       => $dto->tgl_aktif,
            'keterangan'      => $dto->keterangan,
            'status'          => $dto->status,
        ]);
    }

    public function update(Resto $resto, RestoDTO $dto): Resto
    {
        return $this->repository->update($resto, [
            'nama_resto'      => $dto->nama_resto,
            'perusahaan_id'   => $dto->perusahaan_id,
            'brand_id'        => $dto->brand_id,
            'investor_id'     => $dto->investor_id,
            'karyawan_id'     => $dto->karyawan_id,
            'supervisor'      => $dto->supervisor,
            'no_hp_supervisor' => $dto->no_hp_supervisor,
            'stokis'          => $dto->stokis,
            'area'            => $dto->area,
            'kota'          => $dto->kota,
            'alamat'        => $dto->alamat,
            'no_telp'       => $dto->no_telp,
            'tgl_aktif'     => $dto->tgl_aktif,
            'keterangan'    => $dto->keterangan,
            'status'        => $dto->status,
        ]);
    }

    public function delete(Resto $resto): void
    {
        $this->repository->delete($resto);
    }

    public function bulkDelete(array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $id) {
            try {
                $resto = $this->repository->findById((int) $id);
                if (!$resto) {
                    continue;
                }
                $this->delete($resto);
                $deleted++;
            } catch (\Throwable) {
                // skip jika gagal (masih terhubung ke klien/invoice/dll)
            }
        }
        return $deleted;
    }
}
