<?php

namespace App\Domain\Master\Resto\Services;

use App\Domain\Master\Resto\DTO\RestoDTO;
use App\Domain\Master\Resto\Repositories\RestoRepository;
use App\Models\Resto;
use Illuminate\Pagination\LengthAwarePaginator;

class RestoService
{
    public function __construct(private readonly RestoRepository $repository) {}

    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($filters);
    }

    public function findOrFail(int $id): Resto
    {
        $resto = $this->repository->findById($id);
        abort_if(!$resto, 404, 'Resto tidak ditemukan');
        return $resto;
    }

    public function generateKodeResto(string $namaResto): string
    {
        return 'OT-' . $namaResto;
    }

    public function create(RestoDTO $dto): Resto
    {
        $kode = $this->generateKodeResto($dto->nama_resto);

        return $this->repository->create([
            'kode_resto'    => $kode,
            'nama_resto'    => $dto->nama_resto,
            'perusahaan_id' => $dto->perusahaan_id,
            'brand_id'      => $dto->brand_id,
            'investor_id'   => $dto->investor_id,
            'karyawan_id'   => $dto->karyawan_id,
            'area'          => $dto->area,
            'kota'          => $dto->kota,
            'alamat'        => $dto->alamat,
            'no_telp'       => $dto->no_telp,
            'tgl_aktif'     => $dto->tgl_aktif,
            'keterangan'    => $dto->keterangan,
            'status'        => $dto->status,
        ]);
    }

    public function update(Resto $resto, RestoDTO $dto): Resto
    {
        return $this->repository->update($resto, [
            'nama_resto'    => $dto->nama_resto,
            'perusahaan_id' => $dto->perusahaan_id,
            'brand_id'      => $dto->brand_id,
            'investor_id'   => $dto->investor_id,
            'karyawan_id'   => $dto->karyawan_id,
            'area'          => $dto->area,
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
}
