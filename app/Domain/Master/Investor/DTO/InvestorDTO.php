<?php

namespace App\Domain\Master\Investor\DTO;

class InvestorDTO
{
    public function __construct(
        public readonly string  $nama_investor,
        public readonly ?string $ktp,
        public readonly ?string $npwp,
        public readonly ?string $no_hp,
        public readonly ?string $pengelola,
        public readonly ?string $no_hp_pengelola,
        public readonly ?string $kode_cabang,
        public readonly ?string $id_cabang,
        public readonly bool    $status = true,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            nama_investor:    $data['nama_investor'],
            ktp:              $data['ktp'] ?? null,
            npwp:             $data['npwp'] ?? null,
            no_hp:            $data['no_hp'] ?? null,
            pengelola:        $data['pengelola'] ?? null,
            no_hp_pengelola:  $data['no_hp_pengelola'] ?? null,
            kode_cabang:      $data['kode_cabang'] ?? null,
            id_cabang:        $data['id_cabang'] ?? null,
            status:           isset($data['status']) ? (bool) $data['status'] : true,
        );
    }
}
