<?php

namespace App\Domain\Master\Karyawan\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KaryawanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'nik'           => $this->nik,
            'nama_karyawan' => $this->nama_karyawan,
            'perusahaan_id' => $this->perusahaan_id,
            'perusahaan'    => $this->whenLoaded('perusahaan', fn() => [
                'id'                        => $this->perusahaan->id,
                'kode_perusahaan'           => $this->perusahaan->kode_perusahaan,
                'nama_perusahaan'           => $this->perusahaan->nama_perusahaan,
                'nama_singkatan_perusahaan' => $this->perusahaan->nama_singkatan_perusahaan,
            ]),
            'keterangan'      => $this->keterangan,
            'status'          => $this->status,
            'created_by'      => $this->created_by,
            'created_by_name' => $this->whenLoaded('createdBy', fn() => $this->createdBy?->username),
            'updated_by'      => $this->updated_by,
            'updated_by_name' => $this->whenLoaded('updatedBy', fn() => $this->updatedBy?->username),
            'created_at'      => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at'      => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
