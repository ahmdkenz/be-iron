<?php

namespace App\Domain\Finance\Invoice\Resources;

use App\Models\User;
use App\Support\Helpers\RoleHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                         => $this->id,
            'no_invoice'                 => $this->no_invoice,
            'tanggal_invoice'            => $this->tanggal_invoice?->format('Y-m-d'),
            'tanggal_jatuh_tempo'        => $this->tanggal_jatuh_tempo?->format('Y-m-d'),
            'periode_awal'               => $this->periode_awal?->format('Y-m-d'),
            'periode_akhir'              => $this->periode_akhir?->format('Y-m-d'),
            'klien_ar_id'                => $this->klien_ar_id,
            'klien_ar'                   => $this->whenLoaded('klienAr', fn() => [
                'id'          => $this->klienAr->id,
                'kode_klien'  => $this->klienAr->kode_klien,
                'nama_klien'  => $this->klienAr->nama_klien,
                'alias'       => $this->klienAr->alias,
                'tipe_klien'  => $this->klienAr->tipe_klien,
                'tipe_outlet' => $this->klienAr->tipe_outlet,
                'stokis_area' => $this->klienAr->stokis_area,
                'no_npwp'     => $this->klienAr->no_npwp,
                'kat_1'       => $this->klienAr->kat_1,
                'kat_2'       => $this->klienAr->kat_2,
                'karyawan_ar' => $this->klienAr->relationLoaded('karyawanAr') ? [
                    'id'           => $this->klienAr->karyawanAr?->id,
                    'nik'          => $this->klienAr->karyawanAr?->nik,
                    'nama_karyawan'=> $this->klienAr->karyawanAr?->nama_karyawan,
                ] : null,
            ]),
            'perusahaan_id'              => $this->perusahaan_id,
            'perusahaan'                 => $this->whenLoaded('perusahaan', fn() => [
                'id'                        => $this->perusahaan->id,
                'kode_perusahaan'           => $this->perusahaan->kode_perusahaan,
                'nama_singkatan_perusahaan' => $this->perusahaan->nama_singkatan_perusahaan,
                'nama_perusahaan'           => $this->perusahaan->nama_perusahaan,
            ]),
            'karyawan_id'                => $this->karyawan_id,
            'karyawan'                   => $this->whenLoaded('karyawan', fn() => [
                'id'           => $this->karyawan->id,
                'nik'          => $this->karyawan->nik,
                'nama_karyawan'=> $this->karyawan->nama_karyawan,
            ]),
            'no_surat_jalan'             => $this->no_surat_jalan,
            'subtotal'                   => (float) $this->subtotal,
            'tagihan_periode_sebelumnya' => (float) $this->tagihan_periode_sebelumnya,
            'total_tagihan'              => (float) $this->total_tagihan,
            'total_pembayaran'           => (float) $this->total_pembayaran,
            'sisa_tagihan'               => (float) $this->sisa_tagihan,
            'status'                     => $this->status,
            'approval_status'            => $this->approval_status,
            'submitted_at'               => $this->submitted_at?->format('Y-m-d H:i:s'),
            'submitted_by'               => $this->submitted_by,
            'submitted_by_name'          => $this->whenLoaded('submittedBy', fn() => $this->submittedBy?->username),
            'approved_at'                => $this->approved_at?->format('Y-m-d H:i:s'),
            'approved_by'                => $this->approved_by,
            'approved_by_name'           => $this->whenLoaded('approvedBy', fn() => $this->approvedBy?->username),
            'rejected_at'                => $this->rejected_at?->format('Y-m-d H:i:s'),
            'rejected_by'                => $this->rejected_by,
            'rejected_by_name'           => $this->whenLoaded('rejectedBy', fn() => $this->rejectedBy?->username),
            'is_opening_balance'         => $this->is_opening_balance,
            'keterangan'                 => $this->keterangan,
            'items'                      => $this->whenLoaded('items', fn() =>
                InvoiceItemResource::collection($this->items)
            ),
            'pembayarans'                => $this->whenLoaded('pembayarans', fn() =>
                $this->pembayarans->map(fn($p) => [
                    'id'                  => $p->id,
                    'tanggal_pembayaran'  => $p->tanggal_pembayaran?->format('Y-m-d'),
                    'jumlah_pembayaran'   => (float) $p->jumlah_pembayaran,
                    'metode_pembayaran'   => $p->metode_pembayaran,
                    'no_referensi'        => $p->no_referensi,
                    'keterangan'          => $p->keterangan,
                    'created_by_name'     => $p->relationLoaded('createdBy') ? $p->createdBy?->username : null,
                    'created_at'          => $p->created_at?->format('Y-m-d H:i:s'),
                ])
            ),
            'approval_logs'              => $this->whenLoaded('approvalLogs', fn() =>
                $this->approvalLogs->map(fn($log) => [
                    'id'         => $log->id,
                    'action'     => $log->action,
                    'note'       => $log->note,
                    'actor_id'   => $log->actor_id,
                    'actor_name' => $log->relationLoaded('actor') ? $log->actor?->username : null,
                    'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
                ])->values()
            ),
            'created_by'                 => $this->created_by,
            'created_by_name'            => $this->whenLoaded('createdBy', fn() => $this->createdBy?->username),
            'updated_by'                 => $this->updated_by,
            'updated_by_name'            => $this->whenLoaded('updatedBy', fn() => $this->updatedBy?->username),
            'can_approve'                => $this->canApprove($request->user()),
            'can_reject'                 => $this->canReject($request->user()),
            'can_edit'                   => $this->canEdit($request->user()),
            'can_submit'                 => $this->canSubmit($request->user()),
            'can_record_payment'         => $this->isApprovedForFinanceFlow(),
            'can_print'                  => $this->isApprovedForFinanceFlow(),
            'created_at'                 => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at'                 => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function canApprove(?User $user): bool
    {
        return $this->is_opening_balance === true
            && $this->approval_status === 'PENDING'
            && RoleHelper::canApproveOpeningBalance($user);
    }

    private function canReject(?User $user): bool
    {
        return $this->canApprove($user);
    }

    private function canEdit(?User $user): bool
    {
        return $this->is_opening_balance === true
            && $this->status === 'DRAFT'
            && $this->approval_status === 'REJECTED'
            && RoleHelper::canOperateOpeningBalance($user);
    }

    private function canSubmit(?User $user): bool
    {
        return $this->canBeResubmitted()
            && RoleHelper::canOperateOpeningBalance($user);
    }
}
