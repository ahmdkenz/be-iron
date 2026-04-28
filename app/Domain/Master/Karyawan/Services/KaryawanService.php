<?php

namespace App\Domain\Master\Karyawan\Services;

use App\Domain\Master\Karyawan\DTO\KaryawanDTO;
use App\Domain\Master\Karyawan\Repositories\KaryawanRepository;
use App\Models\Karyawan;
use Illuminate\Pagination\LengthAwarePaginator;

class KaryawanService
{
    public function __construct(private readonly KaryawanRepository $repository) {}

    public function getAll(array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($filters);
    }

    public function findOrFail(int $id): Karyawan
    {
        $karyawan = $this->repository->findById($id);
        abort_if(!$karyawan, 404, 'Karyawan tidak ditemukan');
        return $karyawan;
    }

    public function create(KaryawanDTO $dto): Karyawan
    {
        return $this->repository->create([
            'nik'           => $dto->nik,
            'nama_karyawan' => $dto->nama_karyawan,
            'perusahaan_id' => $dto->perusahaan_id,
            'keterangan'    => $dto->keterangan,
            'status'        => $dto->status,
        ]);
    }

    public function update(Karyawan $karyawan, KaryawanDTO $dto): Karyawan
    {
        return $this->repository->update($karyawan, [
            'nik'           => $dto->nik,
            'nama_karyawan' => $dto->nama_karyawan,
            'perusahaan_id' => $dto->perusahaan_id,
            'keterangan'    => $dto->keterangan,
            'status'        => $dto->status,
        ]);
    }

    public function delete(Karyawan $karyawan): void
    {
        $this->repository->delete($karyawan);
    }
}
