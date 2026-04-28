<?php

namespace App\Domain\Master\Barang\DTO;

class BarangDTO
{
    public function __construct(
        public readonly string  $kode_barang,
        public readonly string  $nama_barang,
        public readonly int     $perusahaan_id,
        public readonly int     $brand_id,
        public readonly ?string $spesifikasi,
        public readonly ?string $keterangan,
        public readonly bool    $status = true,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            kode_barang:   strtoupper($data['kode_barang']),
            nama_barang:   $data['nama_barang'],
            perusahaan_id: (int) $data['perusahaan_id'],
            brand_id:      (int) $data['brand_id'],
            spesifikasi:   $data['spesifikasi'] ?? null,
            keterangan:    $data['keterangan'] ?? null,
            status:        isset($data['status']) ? (bool) $data['status'] : true,
        );
    }
}
