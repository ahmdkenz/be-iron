<?php

namespace App\Domain\Master\Investor\Services;

use App\Domain\Master\Investor\DTO\InvestorDTO;
use App\Domain\Master\Investor\Repositories\InvestorRepository;
use App\Models\Investor;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class InvestorService
{
    public function __construct(private readonly InvestorRepository $repository) {}

    public function getAll(array $filters = [], int $perPage = 15, bool $all = false): LengthAwarePaginator|EloquentCollection
    {
        return $all ? $this->repository->all() : $this->repository->paginate($filters, $perPage);
    }

    public function getAllForExport(array $filters = []): EloquentCollection
    {
        return $this->repository->getAllForExport($filters);
    }

    public function findOrFail(int $id): Investor
    {
        $investor = $this->repository->findById($id);
        abort_if(!$investor, 404, 'Investor tidak ditemukan');
        return $investor;
    }

    public function create(InvestorDTO $dto): Investor
    {
        return $this->repository->create([
            'nama_investor'   => $dto->nama_investor,
            'ktp'             => $dto->ktp,
            'npwp'            => $dto->npwp,
            'no_hp'           => $dto->no_hp,
            'pengelola'       => $dto->pengelola,
            'no_hp_pengelola' => $dto->no_hp_pengelola,
            'kode_cabang'     => $dto->kode_cabang,
            'id_cabang'       => $dto->id_cabang,
            'status'          => $dto->status,
        ]);
    }

    public function update(Investor $investor, InvestorDTO $dto): Investor
    {
        return $this->repository->update($investor, [
            'nama_investor'   => $dto->nama_investor,
            'ktp'             => $dto->ktp,
            'npwp'            => $dto->npwp,
            'no_hp'           => $dto->no_hp,
            'pengelola'       => $dto->pengelola,
            'no_hp_pengelola' => $dto->no_hp_pengelola,
            'kode_cabang'     => $dto->kode_cabang,
            'id_cabang'       => $dto->id_cabang,
            'status'          => $dto->status,
        ]);
    }

    public function delete(Investor $investor): void
    {
        $this->repository->delete($investor);
    }

    public function bulkDelete(array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $id) {
            try {
                $investor = $this->repository->findById((int) $id);
                if (!$investor) {
                    continue;
                }
                $this->delete($investor);
                $deleted++;
            } catch (\Throwable) {
                // skip jika gagal (masih terhubung ke resto/dll)
            }
        }
        return $deleted;
    }
}
