<?php

namespace App\Domain\Finance\Invoice\DTO;

class InvoiceDTO
{
    public function __construct(
        public readonly string $no_invoice,
        public readonly int $klien_ar_id,
        public readonly ?int $resto_id,
        public readonly string $tanggal_invoice,
        public readonly ?string $tanggal_jatuh_tempo,
        public readonly string $periode_awal,
        public readonly string $periode_akhir,
        public readonly ?string $no_surat_jalan,
        public readonly ?string $keterangan,
        public readonly array $items,
        public readonly string $status = 'DRAFT',
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            no_invoice:            $data['no_invoice'],
            klien_ar_id:          (int) $data['klien_ar_id'],
            resto_id:              isset($data['resto_id']) ? (int) $data['resto_id'] : null,
            tanggal_invoice:       $data['tanggal_invoice'],
            tanggal_jatuh_tempo:   $data['tanggal_jatuh_tempo'] ?? null,
            periode_awal:          $data['periode_awal'],
            periode_akhir:         $data['periode_akhir'],
            no_surat_jalan:        $data['no_surat_jalan'] ?? null,
            keterangan:            $data['keterangan'] ?? null,
            items:                 $data['items'] ?? [],
            status:                $data['status'] ?? 'DRAFT',
        );
    }
}
