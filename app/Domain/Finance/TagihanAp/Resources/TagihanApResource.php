<?php

namespace App\Domain\Finance\TagihanAp\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TagihanApResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'no_tagihan'          => $this->no_tagihan,
            'no_invoice_vendor'   => $this->no_invoice_vendor,
            'tanggal_tagihan'     => $this->tanggal_tagihan?->format('Y-m-d'),
            'tanggal_jatuh_tempo' => $this->tanggal_jatuh_tempo?->format('Y-m-d'),
            'vendor_ap_id'        => $this->vendor_ap_id,
            'vendor_ap'           => $this->whenLoaded('vendorAp', fn() => $this->vendorAp ? [
                'id'          => $this->vendorAp->id,
                'kode_vendor' => $this->vendorAp->kode_vendor,
                'nama_vendor' => $this->vendorAp->nama_vendor,
            ] : null),
            'perusahaan_id'       => $this->perusahaan_id,
            'karyawan_id'         => $this->karyawan_id,
            'karyawan'            => $this->whenLoaded('karyawan', fn() => $this->karyawan ? [
                'id'            => $this->karyawan->id,
                'nama_karyawan' => $this->karyawan->nama_karyawan,
            ] : null),
            'no_po'               => $this->no_po,
            'no_terima_barang'    => $this->no_terima_barang,
            'source_system'       => $this->source_system,
            'subtotal'            => (float) $this->subtotal,
            'ppn_masukan'         => (float) $this->ppn_masukan,
            'pph23'               => (float) $this->pph23,
            'total_tagihan'       => (float) $this->total_tagihan,
            'total_pembayaran'    => (float) $this->total_pembayaran,
            'total_penyesuaian'   => (float) $this->total_penyesuaian,
            'sisa_tagihan'        => (float) $this->sisa_tagihan,
            'status'              => $this->status,
            'approval_status'     => $this->approval_status,
            'is_opening_balance'  => $this->is_opening_balance,
            'can_edit'            => $this->status === 'DRAFT' && in_array($this->approval_status, ['PENDING', 'REJECTED']),
            'can_resubmit'        => $this->status === 'DRAFT' && $this->approval_status === 'REJECTED',
            'can_approve'         => $this->status === 'DRAFT' && $this->approval_status === 'PENDING',
            'items'               => TagihanApItemResource::collection($this->whenLoaded('items')),
            'submitted_at'        => $this->submitted_at?->toIso8601String(),
            'submitted_by_name'   => $this->whenLoaded('submittedBy', fn() => $this->submittedBy?->username),
            'approved_at'         => $this->approved_at?->toIso8601String(),
            'approved_by_name'    => $this->whenLoaded('approvedBy', fn() => $this->approvedBy?->username),
            'rejected_at'         => $this->rejected_at?->toIso8601String(),
            'rejected_by_name'    => $this->whenLoaded('rejectedBy', fn() => $this->rejectedBy?->username),
            'keterangan'          => $this->keterangan,
            'created_by'          => $this->created_by,
            'created_by_name'     => $this->whenLoaded('createdBy', fn() => $this->createdBy?->username),
            'created_at'          => $this->created_at?->setTimezone('Asia/Jakarta')->format('d-m-Y H:i'),
            'updated_at'          => $this->updated_at?->setTimezone('Asia/Jakarta')->format('d-m-Y H:i'),
        ];
    }
}
