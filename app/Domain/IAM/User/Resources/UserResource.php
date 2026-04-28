<?php

namespace App\Domain\IAM\User\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'username'         => $this->username,
            'email'            => $this->email,
            'no_hp'            => $this->no_hp,
            'status'           => $this->status,
            'karyawan_id'      => $this->karyawan_id,
            'karyawan'         => $this->whenLoaded('karyawan', fn() => [
                'id'            => $this->karyawan->id,
                'nik'           => $this->karyawan->nik,
                'nama_karyawan' => $this->karyawan->nama_karyawan,
            ]),
            'roles'            => $this->getRoleNames(),
            'role'             => $this->roles->first()?->only(['id', 'name', 'nama_role']),
            'created_by'       => $this->created_by,
            'created_by_name'  => $this->whenLoaded('createdBy', fn() => $this->createdBy?->username),
            'updated_by'       => $this->updated_by,
            'updated_by_name'  => $this->whenLoaded('updatedBy', fn() => $this->updatedBy?->username),
            'created_at'       => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at'       => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
