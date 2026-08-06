<?php

namespace App\Domain\Finance\Invoice\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Domain\Finance\Invoice\Resources\OpeningBalanceDetailItemResource;

class OpeningBalanceDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'no_invoice_asal'      => $this->no_invoice_asal,
            'tanggal_invoice_asal' => $this->tanggal_invoice_asal?->format('d-m-Y'),
            'deskripsi'            => $this->deskripsi,
            'jumlah_tagihan_asal'  => (float) $this->jumlah_tagihan_asal,
            'sisa_tagihan_asal'    => (float) $this->sisa_tagihan_asal,
            'keterangan'           => $this->keterangan,
            'kode_resto'           => $this->kode_resto,
            'nama_resto'           => $this->nama_resto,
            'items'                => $this->whenLoaded('items', fn() =>
                OpeningBalanceDetailItemResource::collection($this->items)
            ),
            'created_at'           => $this->created_at?->toIso8601String(),
        ];
    }
}
