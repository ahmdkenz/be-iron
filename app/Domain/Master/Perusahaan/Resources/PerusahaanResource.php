<?php

namespace App\Domain\Master\Perusahaan\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PerusahaanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                        => $this->id,
            'kode_perusahaan'           => $this->kode_perusahaan,
            'nama_perusahaan'           => $this->nama_perusahaan,
            'nama_singkatan_perusahaan' => $this->nama_singkatan_perusahaan,
            'alamat'                    => $this->alamat,
            'kota'                      => $this->kota,
            'kode_pos'                  => $this->kode_pos,
            'no_telp'                   => $this->no_telp,
            'email'                     => $this->email,
            'no_npwp'                   => $this->no_npwp,
            'nama_direktur'             => $this->nama_direktur,
            'segmen'                    => $this->segmen,
            'keterangan'                => $this->keterangan,
            'status'                    => $this->status,
            'created_by'                => $this->created_by,
            'created_by_name'           => $this->whenLoaded('createdBy', fn() => $this->createdBy?->username),
            'updated_by'                => $this->updated_by,
            'updated_by_name'           => $this->whenLoaded('updatedBy', fn() => $this->updatedBy?->username),
            'created_at'                => $this->created_at?->setTimezone('Asia/Jakarta')->format('d-m-Y H:i'),
            'updated_at'                => $this->updated_at?->setTimezone('Asia/Jakarta')->format('d-m-Y H:i'),
        ];
    }
}
