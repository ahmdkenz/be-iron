<?php

namespace App\Domain\Finance\KlienAr\Services;

use App\Domain\Finance\KlienAr\DTO\KlienArDTO;
use App\Domain\Finance\KlienAr\Repositories\KlienArRepository;
use App\Models\Karyawan;
use App\Models\KlienAr;
use Illuminate\Pagination\LengthAwarePaginator;

class KlienArService
{
    public function __construct(private readonly KlienArRepository $repository) {}

    public function generateKodeKlien(?string $tipeKlien = 'MITRA'): string
    {
        $segment = $this->resolveKodeSegment($tipeKlien ?? 'MITRA');
        $prefix  = "AR-{$segment}";
        $count   = KlienAr::withTrashed()
            ->where('kode_klien', 'like', $prefix . '-%')
            ->count();
        $seq     = str_pad($count + 1, 3, '0', STR_PAD_LEFT);

        return "{$prefix}-{$seq}";
    }

    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($filters);
    }

    public function getAll(array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        return $this->repository->getAll($filters);
    }

    public function findOrFail(int $id): KlienAr
    {
        $klien = $this->repository->findById($id);
        abort_if(!$klien, 404, 'Klien AR tidak ditemukan');
        return $klien;
    }

    public function create(KlienArDTO $dto): KlienAr
    {
        $perusahaanId = $dto->perusahaan_id
            ?? Karyawan::find($dto->karyawan_ar_id)?->perusahaan_id;

        return $this->repository->create([
            'kode_klien'    => $this->generateKodeKlien($dto->tipe_klien),
            'nama_klien'    => $dto->nama_klien,
            'tipe_klien'    => $dto->tipe_klien,
            'no_npwp'       => $dto->no_npwp,
            'perusahaan_id' => $perusahaanId,
            'karyawan_ar_id'=> $dto->karyawan_ar_id,
            'resto_id'      => $dto->resto_id,
            'status'        => $dto->status,
            'created_by'    => auth()->id(),
        ]);
    }

    public function update(KlienAr $klien, KlienArDTO $dto): KlienAr
    {
        $perusahaanId = $dto->perusahaan_id
            ?? $klien->perusahaan_id
            ?? Karyawan::find($dto->karyawan_ar_id)?->perusahaan_id;

        return $this->repository->update($klien, [
            // Keep historical client codes stable for existing records.
            'kode_klien'    => $klien->kode_klien,
            'nama_klien'    => $dto->nama_klien,
            'tipe_klien'    => $dto->tipe_klien,
            'no_npwp'       => $dto->no_npwp,
            'perusahaan_id' => $perusahaanId,
            'karyawan_ar_id'=> $dto->karyawan_ar_id,
            'resto_id'      => $dto->resto_id,
            'status'        => $dto->status,
            'updated_by'    => auth()->id(),
        ]);
    }

    public function delete(KlienAr $klien): void
    {
        abort_if(
            $klien->invoices()->exists(),
            422,
            'Klien AR tidak dapat dihapus karena memiliki data invoice'
        );
        $this->repository->delete($klien);
    }

    private function resolveKodeSegment(string $tipeKlien): string
    {
        return in_array(strtoupper($tipeKlien), ['RESTO', 'MITRA']) ? 'B2C' : 'B2B';
    }

}
