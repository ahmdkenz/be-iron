<?php

namespace App\Domain\Finance\TagihanAp\DTO;

class TagihanApDTO
{
    public function __construct(
        public readonly ?string $no_tagihan,
        public readonly ?string $no_invoice_vendor,
        public readonly int $vendor_ap_id,
        public readonly string $tanggal_tagihan,
        public readonly ?string $tanggal_jatuh_tempo,
        public readonly ?string $no_po,
        public readonly ?string $no_terima_barang,
        public readonly float $ppn_masukan,
        public readonly float $pph23,
        public readonly ?string $keterangan,
        public readonly array $items,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            no_tagihan:          $data['no_tagihan'] ?? null,
            no_invoice_vendor:   $data['no_invoice_vendor'] ?? null,
            vendor_ap_id:        (int) $data['vendor_ap_id'],
            tanggal_tagihan:     $data['tanggal_tagihan'],
            tanggal_jatuh_tempo: $data['tanggal_jatuh_tempo'] ?? null,
            no_po:               $data['no_po'] ?? null,
            no_terima_barang:    $data['no_terima_barang'] ?? null,
            ppn_masukan:         (float) ($data['ppn_masukan'] ?? 0),
            pph23:               (float) ($data['pph23'] ?? 0),
            keterangan:          $data['keterangan'] ?? null,
            items:               $data['items'] ?? [],
        );
    }
}
