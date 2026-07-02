<?php

namespace App\Domain\Master\Barang\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BarangResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'kode_barang'    => $this->kode_barang,
            'nama_barang'    => $this->nama_barang,
            'spesifikasi'    => $this->spesifikasi,
            'keterangan'     => $this->keterangan,
            'status'         => $this->status,
            'created_by'     => $this->created_by,
            'created_by_name' => $this->whenLoaded('createdBy', fn() => $this->createdBy?->username),
            'updated_by'     => $this->updated_by,
            'updated_by_name' => $this->whenLoaded('updatedBy', fn() => $this->updatedBy?->username),
            'created_at'     => $this->created_at?->setTimezone('Asia/Jakarta')->format('d-m-Y H:i'),
            'updated_at'     => $this->updated_at?->setTimezone('Asia/Jakarta')->format('d-m-Y H:i'),
        ];
    }
}
