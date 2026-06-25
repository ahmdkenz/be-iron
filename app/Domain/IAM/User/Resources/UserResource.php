<?php

namespace App\Domain\IAM\User\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'username'          => $this->username,
            'email'             => $this->email,
            'email_verified_at' => $this->email_verified_at?->setTimezone('Asia/Jakarta')->format('d-m-Y H:i'),
            'no_hp'             => $this->no_hp,
            'status'            => $this->status,
            'karyawan_id'      => $this->karyawan_id,
            'karyawan'         => $this->whenLoaded('karyawan', fn() => [
                'id'            => $this->karyawan->id,
                'nik'           => $this->karyawan->nik,
                'nama_karyawan' => $this->karyawan->nama_karyawan,
                'perusahaan_id' => $this->karyawan->perusahaan_id,
                'perusahaan'    => $this->karyawan->relationLoaded('perusahaan')
                    ? ['id' => $this->karyawan->perusahaan?->id, 'nama_perusahaan' => $this->karyawan->perusahaan?->nama_perusahaan]
                    : null,
            ]),
            'roles'            => $this->getRoleNames(),
            'role'             => $this->roles->first()?->only(['id', 'name', 'nama_role']),
            'created_by'       => $this->created_by,
            'created_by_name'  => $this->whenLoaded('createdBy', fn() => $this->createdBy?->username),
            'updated_by'       => $this->updated_by,
            'updated_by_name'  => $this->whenLoaded('updatedBy', fn() => $this->updatedBy?->username),
            'created_at'       => $this->created_at?->setTimezone('Asia/Jakarta')->format('d-m-Y H:i'),
            'updated_at'       => $this->updated_at?->setTimezone('Asia/Jakarta')->format('d-m-Y H:i'),
        ];
    }
}
