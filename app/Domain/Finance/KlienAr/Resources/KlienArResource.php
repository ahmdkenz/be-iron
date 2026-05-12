<?php

namespace App\Domain\Finance\KlienAr\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KlienArResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'kode_klien'     => $this->kode_klien,
            'nama_klien'     => $this->nama_klien,
            'tipe_klien'     => $this->tipe_klien,
            'no_npwp'        => $this->no_npwp,
            'no_wa'          => $this->no_wa,
            'perusahaan_id'  => $this->perusahaan_id,
            'perusahaan'     => $this->whenLoaded('perusahaan', fn() => [
                'id'                        => $this->perusahaan->id,
                'kode_perusahaan'           => $this->perusahaan->kode_perusahaan,
                'nama_singkatan_perusahaan' => $this->perusahaan->nama_singkatan_perusahaan,
                'nama_perusahaan'           => $this->perusahaan->nama_perusahaan,
            ]),
            'karyawan_ar_id' => $this->karyawan_ar_id,
            'karyawan_ar'    => $this->whenLoaded('karyawanAr', fn() => [
                'id'           => $this->karyawanAr->id,
                'nik'          => $this->karyawanAr->nik,
                'nama_karyawan'=> $this->karyawanAr->nama_karyawan,
            ]),
            'resto_id'       => $this->resto_id,
            'resto'          => $this->whenLoaded('resto', fn() => $this->resto ? [
                'id'         => $this->resto->id,
                'kode_resto' => $this->resto->kode_resto,
                'nama_resto' => $this->resto->nama_resto,
                'investor'   => $this->resto->relationLoaded('investor') && $this->resto->investor ? [
                    'id'              => $this->resto->investor->id,
                    'nama_investor'   => $this->resto->investor->nama_investor,
                    'pengelola'       => $this->resto->investor->pengelola,
                    'no_hp_pengelola' => $this->resto->investor->no_hp_pengelola,
                ] : null,
            ] : null),
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
