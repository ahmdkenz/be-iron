<?php

namespace App\Domain\Finance\KlienAr\Services;

use App\Domain\Finance\KlienAr\DTO\KlienArDTO;
use App\Domain\Finance\KlienAr\Repositories\KlienArRepository;
use App\Models\Invoice;
use App\Models\Karyawan;
use App\Models\KlienAr;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class KlienArService
{
    public function __construct(private readonly KlienArRepository $repository) {}

    public function generateKodeKlien(?string $tipeKlien = 'RESTO'): string
    {
        $segment = $this->resolveKodeSegment($tipeKlien ?? 'RESTO');
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
            ?? Karyawan::find($dto->karyawan_ar_id)?->perusahaan_id;

        return $this->repository->create([
            'kode_klien'    => $dto->kode_klien,
            'nama_klien'    => $dto->nama_klien,
            'tipe_klien'    => $dto->tipe_klien,
            'no_npwp'       => $dto->no_npwp,
            'no_wa'         => $dto->no_wa,
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
            'no_wa'         => $dto->no_wa,
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
            'Client tidak dapat dihapus karena memiliki data invoice'
        );
        $this->repository->delete($klien);
    }

    public function generateBundleToken(KlienAr $klien): KlienAr
    {
        $klien->update([
            'bundle_share_token' => Str::uuid()->toString(),
            'updated_by'         => auth()->id(),
        ]);

        return $klien->fresh();
    }

    public function getPublicBundleData(string $token): array
    {
        $klien = KlienAr::where('bundle_share_token', $token)->firstOrFail();

        $invoices = Invoice::with('resto')
            ->where('klien_ar_id', $klien->id)
            ->where('status', '!=', 'DRAFT')
            ->orderBy('tanggal_invoice')
            ->get();

        $grouped = $invoices->groupBy(fn($inv) => $inv->resto_id ?? 0);

        $restos = $grouped->map(function ($items, $restoId) {
            $restoNama = $restoId
                ? ($items->first()->resto?->nama_resto ?? 'Resto #' . $restoId)
                : 'Umum';

            return [
                'resto_id'      => $restoId ?: null,
                'nama_resto'    => $restoNama,
                'invoices'      => $items->map(fn($inv) => [
                    'id'             => $inv->id,
                    'no_invoice'     => $inv->no_invoice,
                    'tanggal_invoice'=> $inv->tanggal_invoice?->format('d-m-Y'),
                    'periode_awal'   => $inv->periode_awal?->format('d-m-Y'),
                    'periode_akhir'  => $inv->periode_akhir?->format('d-m-Y'),
                    'total_tagihan'  => (float) $inv->total_tagihan,
                    'sisa_tagihan'   => (float) $inv->sisa_tagihan,
                    'status'         => $inv->status,
                    'share_url'      => $inv->prepared_token
                        ? url('/api/v1/invoices/public/' . $inv->prepared_token)
                        : null,
                ])->values(),
                'subtotal_tagihan' => (float) $items->sum('total_tagihan'),
                'subtotal_sisa'    => (float) $items->sum('sisa_tagihan'),
            ];
        })->values();

        return [
            'klien' => [
                'id'         => $klien->id,
                'kode_klien' => $klien->kode_klien,
                'nama_klien' => $klien->nama_klien,
                'no_wa'      => $klien->no_wa,
            ],
            'restos'       => $restos,
            'grand_total'  => (float) $invoices->sum('total_tagihan'),
            'grand_sisa'   => (float) $invoices->sum('sisa_tagihan'),
        ];
    }

    private function resolveKodeSegment(string $tipeKlien): string
    {
        return strtoupper($tipeKlien) === 'RESTO' ? 'B2C' : 'B2B';
    }

}
