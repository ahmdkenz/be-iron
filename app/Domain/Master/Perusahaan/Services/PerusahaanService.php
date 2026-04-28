<?php

namespace App\Domain\Master\Perusahaan\Services;

use App\Domain\Master\Perusahaan\DTO\PerusahaanDTO;
use App\Domain\Master\Perusahaan\Repositories\PerusahaanRepository;
use App\Models\Perusahaan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class PerusahaanService
{
    public function __construct(private readonly PerusahaanRepository $repository) {}

    public function getAll(array $filters = [], bool $all = false): LengthAwarePaginator|Collection
    {
        return $all
            ? $this->repository->all($filters)
            : $this->repository->paginate($filters);
    }

    public function findOrFail(int $id): Perusahaan
    {
        $perusahaan = $this->repository->findById($id);
        abort_if(!$perusahaan, 404, 'Perusahaan tidak ditemukan');
        return $perusahaan;
    }

    public function create(PerusahaanDTO $dto): Perusahaan
    {
        return $this->repository->create([
            'kode_perusahaan'           => strtoupper($dto->kode_perusahaan),
            'nama_perusahaan'           => $dto->nama_perusahaan,
            'nama_singkatan_perusahaan' => strtoupper($dto->nama_singkatan_perusahaan),
            'alamat'                    => $dto->alamat,
            'kota'                      => $dto->kota,
            'kode_pos'                  => $dto->kode_pos,
            'no_telp'                   => $dto->no_telp,
            'email'                     => $dto->email,
            'no_npwp'                   => $dto->no_npwp,
            'keterangan'                => $dto->keterangan,
            'status'                    => $dto->status,
        ]);
    }

    public function update(Perusahaan $perusahaan, PerusahaanDTO $dto): Perusahaan
    {
        return $this->repository->update($perusahaan, [
            'nama_perusahaan'           => $dto->nama_perusahaan,
            'nama_singkatan_perusahaan' => strtoupper($dto->nama_singkatan_perusahaan),
            'alamat'                    => $dto->alamat,
            'kota'                      => $dto->kota,
            'kode_pos'                  => $dto->kode_pos,
            'no_telp'                   => $dto->no_telp,
            'email'                     => $dto->email,
            'no_npwp'                   => $dto->no_npwp,
            'keterangan'                => $dto->keterangan,
            'status'                    => $dto->status,
        ]);
    }

    public function delete(Perusahaan $perusahaan): void
    {
        $this->repository->delete($perusahaan);
    }
}
