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
            'qty_po'       => $this->qty_po !== null ? (float) $this->qty_po : null,
            'satuan'       => $this->satuan,
            'harga_satuan' => (float) $this->harga_satuan,
            'ppn'          => $this->ppn !== null ? (float) $this->ppn : null,
            'status_detail_terima_po' => $this->status_detail_terima_po,
            'qty_tolak'    => $this->qty_tolak !== null ? (float) $this->qty_tolak : null,
            'keterangan_tolak' => $this->keterangan_tolak,
            'subtotal'     => (float) $this->subtotal,
            'keterangan'   => $this->keterangan,
        ];
    }
}
