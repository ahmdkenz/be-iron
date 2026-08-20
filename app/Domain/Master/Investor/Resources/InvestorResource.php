<?php

namespace App\Domain\Master\Investor\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'nama_investor'   => $this->nama_investor,
            'ktp'             => $this->ktp,
            'npwp'            => $this->npwp,
            'no_hp'           => $this->no_hp,
            'pengelola'       => $this->pengelola,
            'no_hp_pengelola' => $this->no_hp_pengelola,
            'email'           => $this->email,
            'kode_cabang'     => $this->kode_cabang,
            'id_cabang'       => $this->id_cabang,
            'status'          => $this->status,
            'created_by'      => $this->created_by,
            'created_by_name' => $this->whenLoaded('createdBy', fn() => $this->createdBy?->username),
            'updated_by'      => $this->updated_by,
            'updated_by_name' => $this->whenLoaded('updatedBy', fn() => $this->updatedBy?->username),
            'created_at'      => $this->created_at?->setTimezone('Asia/Jakarta')->format('d-m-Y H:i'),
            'updated_at'      => $this->updated_at?->setTimezone('Asia/Jakarta')->format('d-m-Y H:i'),
        ];
    }
}
