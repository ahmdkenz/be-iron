<?php

namespace App\Domain\Finance\KlienAr\DTO;

class KlienArDTO
{
    public function __construct(
        public readonly string $nama_klien,
        public readonly ?string $kode_klien,
        public readonly ?string $alias,
        public readonly string $tipe_klien,
        public readonly ?string $tipe_outlet,
        public readonly ?string $stokis_area,
        public readonly ?string $no_npwp,
        public readonly int $perusahaan_id,
        public readonly int $karyawan_ar_id,
        public readonly ?int $resto_id,
        public readonly bool $status = true,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            nama_klien:     $data['nama_klien'],
            kode_klien:     isset($data['kode_klien']) ? strtoupper($data['kode_klien']) : null,
            alias:          $data['alias'] ?? null,
            tipe_klien:     $data['tipe_klien'],
            tipe_outlet:    $data['tipe_outlet'] ?? null,
            stokis_area:    $data['stokis_area'] ?? null,
            no_npwp:        $data['no_npwp'] ?? null,
            perusahaan_id:  (int) $data['perusahaan_id'],
            karyawan_ar_id: (int) $data['karyawan_ar_id'],
            resto_id:       isset($data['resto_id']) ? (int) $data['resto_id'] : null,
            status:         isset($data['status']) ? (bool) $data['status'] : true,
        );
    }
}
