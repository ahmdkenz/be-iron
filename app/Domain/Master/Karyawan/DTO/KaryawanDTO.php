<?php

namespace App\Domain\Master\Karyawan\DTO;

class KaryawanDTO
{
    public function __construct(
        public readonly string $nik,
        public readonly string $nama_karyawan,
        public readonly int $perusahaan_id,
        public readonly ?string $keterangan,
        public readonly bool $status = true,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            nik:           $data['nik'],
            nama_karyawan: $data['nama_karyawan'],
            perusahaan_id: (int) $data['perusahaan_id'],
            keterangan:    $data['keterangan'] ?? null,
            status:        isset($data['status']) ? (bool) $data['status'] : true,
        );
    }
}
