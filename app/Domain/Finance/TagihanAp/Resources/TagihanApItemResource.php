<?php

namespace App\Domain\Finance\TagihanAp\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TagihanApItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'tagihan_ap_id'=> $this->tagihan_ap_id,
            'barang_id'    => $this->barang_id,
            'kode_barang'  => $this->kode_barang ?? $this->barang?->kode_barang,
            'nama_barang'  => $this->nama_barang,
            'qty'          => (float) $this->qty,
            'satuan'       => $this->satuan,
            'harga_satuan' => (float) $this->harga_satuan,
            'subtotal'     => (float) $this->subtotal,
            'keterangan'   => $this->keterangan,
        ];
    }
}
