<?php

namespace App\Domain\Master\Brand\Services;

use App\Domain\Master\Brand\DTO\BrandDTO;
use App\Domain\Master\Brand\Repositories\BrandRepository;
use App\Models\Brand;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class BrandService
{
    public function __construct(private readonly BrandRepository $repository) {}

    public function getAll(array $filters = [], bool $all = false): LengthAwarePaginator|Collection
    {
        return $all
            ? $this->repository->all()
            : $this->repository->paginate($filters);
    }

    public function findOrFail(int $id): Brand
    {
        $brand = $this->repository->findById($id);
        abort_if(!$brand, 404, 'Brand tidak ditemukan');
        return $brand;
    }

    public function create(BrandDTO $dto): Brand
    {
        return $this->repository->create([
            'kode_brand' => $dto->kode_brand,
            'nama_brand' => $dto->nama_brand,
            'keterangan' => $dto->keterangan,
            'status'     => $dto->status,
        ]);
    }

    public function update(Brand $brand, BrandDTO $dto): Brand
    {
        return $this->repository->update($brand, [
            'kode_brand' => $dto->kode_brand,
            'nama_brand' => $dto->nama_brand,
            'keterangan' => $dto->keterangan,
            'status'     => $dto->status,
        ]);
    }

    public function delete(Brand $brand): void
    {
        $this->repository->delete($brand);
    }
}
