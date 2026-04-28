<?php

namespace App\Domain\Finance\PembayaranAr\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PembayaranArResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'invoice_id'         => $this->invoice_id,
            'no_invoice'         => $this->whenLoaded('invoice', fn() => $this->invoice?->no_invoice),
            'klien'              => $this->whenLoaded('invoice', fn() => $this->invoice?->klienAr?->nama_klien),
            'perusahaan'         => $this->whenLoaded('invoice', fn() => $this->invoice?->perusahaan?->nama_singkatan_perusahaan),
            'tanggal_pembayaran' => $this->tanggal_pembayaran?->format('Y-m-d'),
            'jumlah_pembayaran'  => (float) $this->jumlah_pembayaran,
            'metode_pembayaran'  => $this->metode_pembayaran,
            'no_referensi'       => $this->no_referensi,
            'keterangan'         => $this->keterangan,
            'created_by'         => $this->created_by,
            'created_by_name'    => $this->whenLoaded('createdBy', fn() => $this->createdBy?->username),
            'created_at'         => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
