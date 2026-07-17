<?php

namespace App\Domain\Finance\VendorAp\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorApResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'kode_vendor'      => $this->kode_vendor,
            'nama_vendor'      => $this->nama_vendor,
            'display_label'    => "{$this->nama_vendor} ({$this->kode_vendor})",
            'no_npwp'          => $this->no_npwp,
            'status_pkp'       => $this->status_pkp,
            'bank_nama'        => $this->bank_nama,
            'bank_no_rekening' => $this->bank_no_rekening,
            'bank_atas_nama'   => $this->bank_atas_nama,
            'karyawan_ap_id'   => $this->karyawan_ap_id,
            'karyawan_ap'      => $this->whenLoaded('karyawanAp', fn() => $this->karyawanAp ? [
                'id'            => $this->karyawanAp->id,
                'nik'           => $this->karyawanAp->nik,
                'nama_karyawan' => $this->karyawanAp->nama_karyawan,
            ] : null),
            'status'          => $this->status,
            'created_by'      => $this->created_by,
            'created_by_name' => $this->whenLoaded('createdBy', fn() => $this->createdBy?->username),
            'updated_by'      => $this->updated_by,
            'updated_by_name' => $this->whenLoaded('updatedBy', fn() => $this->updatedBy?->username),
            'created_at'      => $this->created_at?->setTimezone('Asia/Jakarta')->format('d-m-Y H:i'),
            'updated_at'      => $this->updated_at?->setTimezone('Asia/Jakarta')->format('d-m-Y H:i'),
            'deleted_at'      => $this->deleted_at?->setTimezone('Asia/Jakarta')->format('d-m-Y H:i'),
        ];
    }
}
