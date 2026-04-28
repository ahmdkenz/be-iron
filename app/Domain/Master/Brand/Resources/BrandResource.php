<?php

namespace App\Domain\Master\Brand\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'kode_brand'  => $this->kode_brand,
            'nama_brand'  => $this->nama_brand,
            'keterangan'  => $this->keterangan,
            'status'         => $this->status,
            'created_by'     => $this->created_by,
            'created_by_name' => $this->whenLoaded('createdBy', fn() => $this->createdBy?->username),
            'updated_by'     => $this->updated_by,
            'updated_by_name' => $this->whenLoaded('updatedBy', fn() => $this->updatedBy?->username),
            'created_at'     => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at'     => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
