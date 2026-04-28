<?php

namespace App\Domain\Master\Brand\DTO;

class BrandDTO
{
    public function __construct(
        public readonly string  $kode_brand,
        public readonly string  $nama_brand,
        public readonly ?string $keterangan,
        public readonly bool    $status = true,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            kode_brand: strtoupper($data['kode_brand']),
            nama_brand: $data['nama_brand'],
            keterangan: $data['keterangan'] ?? null,
            status:     isset($data['status']) ? (bool) $data['status'] : true,
        );
    }
}
