<?php

namespace App\Domain\Master\Resto\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'kode_resto'     => $this->kode_resto,
            'nama_resto'     => $this->nama_resto,
            'investor_id'    => $this->investor_id,
            'investor'       => $this->whenLoaded('investor', fn() => $this->investor ? [
                'id'           => $this->investor->id,
                'nama_investor' => $this->investor->nama_investor,
            ] : null),
            'perusahaan_id'  => $this->perusahaan_id,
            'perusahaan'     => $this->whenLoaded('perusahaan', fn() => $this->perusahaan ? [
                'id'                        => $this->perusahaan->id,
                'kode_perusahaan'           => $this->perusahaan->kode_perusahaan,
                'nama_perusahaan'           => $this->perusahaan->nama_perusahaan,
                'nama_singkatan_perusahaan' => $this->perusahaan->nama_singkatan_perusahaan,
            ] : null),
            'brand_id'       => $this->brand_id,
            'brand'          => $this->whenLoaded('brand', fn() => $this->brand ? [
                'id'         => $this->brand->id,
                'kode_brand' => $this->brand->kode_brand,
                'nama_brand' => $this->brand->nama_brand,
            ] : null),
            'karyawan_id'    => $this->karyawan_id,
            'pic'            => $this->whenLoaded('pic', fn() => $this->pic ? [
                'id'           => $this->pic->id,
                'nik'          => $this->pic->nik,
                'nama_karyawan' => $this->pic->nama_karyawan,
            ] : null),
            'area'           => $this->area,
            'kota'           => $this->kota,
            'alamat'         => $this->alamat,
            'no_telp'        => $this->no_telp,
            'tgl_aktif'      => $this->tgl_aktif?->format('Y-m-d'),
            'keterangan'     => $this->keterangan,
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
