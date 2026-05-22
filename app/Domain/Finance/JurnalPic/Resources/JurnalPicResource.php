<?php

namespace App\Domain\Finance\JurnalPic\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JurnalPicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $bankDetail = $this->whenLoaded('bankStatementDetail', fn() => $this->bankStatementDetail)
            ?: $this->whenLoaded('sumberPembayaran', fn() => $this->sumberPembayaran?->bankStatementDetail);

        return [
            'id'                   => $this->id,
            'no_referensi'         => $this->no_referensi,
            'tanggal_pembayaran'   => $this->tanggal_pembayaran?->format('Y-m-d'),
            'jumlah_pembayaran'    => (float) $this->jumlah_pembayaran,
            'metode_pembayaran'    => $this->metode_pembayaran,
            'keterangan'           => $this->keterangan,

            // Info Invoice
            'invoice_id'           => $this->invoice_id,
            'no_invoice'           => $this->whenLoaded('invoice', fn() => $this->invoice?->no_invoice),
            'tanggal_invoice'      => $this->whenLoaded('invoice', fn() => $this->invoice?->tanggal_invoice?->format('Y-m-d')),

            // Info Klien
            'klien'                => $this->whenLoaded('invoice', fn() => $this->invoice?->klienAr?->nama_klien),
            'kode_klien'           => $this->whenLoaded('invoice', fn() => $this->invoice?->klienAr?->kode_klien),

            // Info PIC yang bertanggung jawab atas invoice
            'pic_id'               => $this->whenLoaded('invoice', fn() => $this->invoice?->karyawan_id),
            'pic_nama'             => $this->whenLoaded('invoice', fn() => $this->invoice?->karyawan?->nama_karyawan),

            // Info Perusahaan
            'perusahaan'           => $this->whenLoaded('invoice', fn() => $this->invoice?->perusahaan?->nama_singkatan_perusahaan),

            // Info yang input pembayaran
            'input_oleh'           => $this->whenLoaded('createdBy', fn() => $this->createdBy?->username),
            'created_at'           => $this->created_at?->format('Y-m-d H:i:s'),

            // Status rekonsiliasi bank
            'status_rekonsiliasi'  => $bankDetail ? $bankDetail->status_cocok : null,
            'tgl_rekonsiliasi'     => $bankDetail ? $bankDetail->tanggal?->format('Y-m-d') : null,
            'no_ref_bank'          => $bankDetail ? $bankDetail->no_referensi : null,
        ];
    }
}
