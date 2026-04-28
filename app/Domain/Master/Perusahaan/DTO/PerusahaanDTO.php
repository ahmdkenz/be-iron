<?php

namespace App\Domain\Master\Perusahaan\DTO;

class PerusahaanDTO
{
    public function __construct(
        public readonly string $kode_perusahaan,
        public readonly string $nama_perusahaan,
        public readonly string $nama_singkatan_perusahaan,
        public readonly ?string $alamat,
        public readonly ?string $kota,
        public readonly ?string $kode_pos,
        public readonly ?string $no_telp,
        public readonly ?string $email,
        public readonly ?string $no_npwp,
        public readonly ?string $keterangan,
        public readonly bool $status = true,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            kode_perusahaan:           $data['kode_perusahaan'],
            nama_perusahaan:           $data['nama_perusahaan'],
            nama_singkatan_perusahaan: $data['nama_singkatan_perusahaan'],
            alamat:                    $data['alamat'] ?? null,
            kota:                      $data['kota'] ?? null,
            kode_pos:                  $data['kode_pos'] ?? null,
            no_telp:                   $data['no_telp'] ?? null,
            email:                     $data['email'] ?? null,
            no_npwp:                   $data['no_npwp'] ?? null,
            keterangan:                $data['keterangan'] ?? null,
            status:                    isset($data['status']) ? (bool) $data['status'] : true,
        );
    }
}
