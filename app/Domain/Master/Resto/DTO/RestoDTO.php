<?php

namespace App\Domain\Master\Resto\DTO;

class RestoDTO
{
    public function __construct(
        public readonly string  $nama_resto,
        public readonly ?int    $perusahaan_id,
        public readonly ?int    $brand_id,
        public readonly ?int    $investor_id,
        public readonly ?int    $karyawan_id,
        public readonly ?string $area,
        public readonly ?string $kota,
        public readonly ?string $alamat,
        public readonly ?string $no_telp,
        public readonly ?string $tgl_aktif,
        public readonly ?string $keterangan,
        public readonly bool    $status = true,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            nama_resto:    $data['nama_resto'],
            perusahaan_id: isset($data['perusahaan_id']) ? (int) $data['perusahaan_id'] : null,
            brand_id:      isset($data['brand_id']) ? (int) $data['brand_id'] : null,
            investor_id:   isset($data['investor_id']) ? (int) $data['investor_id'] : null,
            karyawan_id:   isset($data['karyawan_id']) ? (int) $data['karyawan_id'] : null,
            area:          $data['area'] ?? null,
            kota:          $data['kota'] ?? null,
            alamat:        $data['alamat'] ?? null,
            no_telp:       $data['no_telp'] ?? null,
            tgl_aktif:     $data['tgl_aktif'] ?? null,
            keterangan:    $data['keterangan'] ?? null,
            status:        isset($data['status']) ? (bool) $data['status'] : true,
        );
    }
}
