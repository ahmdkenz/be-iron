<?php

namespace App\Domain\Finance\OpeningBalanceAp\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OpeningBalanceApDetailItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'barang_id'    => $this->barang_id,
            'barang'       => $this->whenLoaded('barang', fn() => $this->barang ? [
                'id'          => $this->barang->id,
                'kode_barang' => $this->barang->kode_barang,
                'nama_barang' => $this->barang->nama_barang,
            ] : null),
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
