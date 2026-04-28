<?php

namespace App\Domain\Master\Barang\Services;

use App\Domain\Master\Barang\DTO\BarangDTO;
use App\Domain\Master\Barang\Repositories\BarangRepository;
use App\Models\Barang;
use Illuminate\Pagination\LengthAwarePaginator;

class BarangService
{
    public function __construct(private readonly BarangRepository $repository) {}

    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($filters);
    }

    public function findOrFail(int $id): Barang
    {
        $barang = $this->repository->findById($id);
        abort_if(!$barang, 404, 'Barang tidak ditemukan');
        return $barang;
    }

    public function create(BarangDTO $dto): Barang
    {
        return $this->repository->create([
            'kode_barang'   => $dto->kode_barang,
            'nama_barang'   => $dto->nama_barang,
            'perusahaan_id' => $dto->perusahaan_id,
            'brand_id'      => $dto->brand_id,
            'spesifikasi'   => $dto->spesifikasi,
            'keterangan'    => $dto->keterangan,
            'status'        => $dto->status,
        ]);
    }

    public function update(Barang $barang, BarangDTO $dto): Barang
    {
        return $this->repository->update($barang, [
            'kode_barang'   => $dto->kode_barang,
            'nama_barang'   => $dto->nama_barang,
            'perusahaan_id' => $dto->perusahaan_id,
            'brand_id'      => $dto->brand_id,
            'spesifikasi'   => $dto->spesifikasi,
            'keterangan'    => $dto->keterangan,
            'status'        => $dto->status,
        ]);
    }

    public function delete(Barang $barang): void
    {
        $this->repository->delete($barang);
    }
}
