<?php

namespace App\Domain\Finance\VendorAp\DTO;

class VendorApDTO
{
    public function __construct(
        public readonly string $nama_vendor,
        public readonly ?string $no_npwp,
        public readonly bool $status_pkp,
        public readonly ?string $bank_nama,
        public readonly ?string $bank_no_rekening,
        public readonly ?string $bank_atas_nama,
        public readonly int $karyawan_ap_id,
        public readonly bool $status = true,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            nama_vendor:       $data['nama_vendor'],
            no_npwp:           $data['no_npwp'] ?? null,
            status_pkp:        isset($data['status_pkp']) ? (bool) $data['status_pkp'] : false,
            bank_nama:         $data['bank_nama'] ?? null,
            bank_no_rekening:  $data['bank_no_rekening'] ?? null,
            bank_atas_nama:    $data['bank_atas_nama'] ?? null,
            karyawan_ap_id:    (int) $data['karyawan_ap_id'],
            status:            isset($data['status']) ? (bool) $data['status'] : true,
        );
    }
}
