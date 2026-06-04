<?php

namespace App\Domain\Finance\Invoice\Resources;

use App\Models\User;
use App\Support\Helpers\RoleHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Domain\Finance\Invoice\Resources\OpeningBalanceDetailResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                         => $this->id,
            'no_invoice'                 => $this->no_invoice,
            'tanggal_invoice'            => $this->tanggal_invoice?->format('d-m-Y'),
            'periode_awal'               => $this->periode_awal?->format('d-m-Y'),
            'periode_akhir'              => $this->periode_akhir?->format('d-m-Y'),
            'klien_ar_id'                => $this->klien_ar_id,
            'resto_id'                   => $this->resto_id,
            'resto'                      => $this->whenLoaded('resto', fn() => $this->resto ? [
                'id'         => $this->resto->id,
                'kode_resto' => $this->resto->kode_resto,
                'nama_resto' => $this->resto->nama_resto,
            ] : null),
            'klien_ar'                   => $this->whenLoaded('klienAr', fn() => [
                'id'           => $this->klienAr->id,
                'kode_klien'   => $this->klienAr->kode_klien,
                'nama_klien'   => $this->klienAr->nama_klien,
                'tipe_klien'   => $this->klienAr->tipe_klien,
                'no_npwp'      => $this->klienAr->no_npwp,
                'no_wa'        => $this->klienAr->no_wa,
                'karyawan_ar'  => $this->klienAr->relationLoaded('karyawanAr') ? [
                    'id'           => $this->klienAr->karyawanAr?->id,
                    'nik'          => $this->klienAr->karyawanAr?->nik,
                    'nama_karyawan'=> $this->klienAr->karyawanAr?->nama_karyawan,
                ] : null,
                'resto'        => $this->klienAr->relationLoaded('resto') && $this->klienAr->resto ? [
                    'id'         => $this->klienAr->resto->id,
                    'kode_resto' => $this->klienAr->resto->kode_resto,
                    'nama_resto' => $this->klienAr->resto->nama_resto,
                    'investor'   => $this->klienAr->resto->relationLoaded('investor') && $this->klienAr->resto->investor ? [
                        'id'              => $this->klienAr->resto->investor->id,
                        'nama_investor'   => $this->klienAr->resto->investor->nama_investor,
                        'no_hp'           => $this->klienAr->resto->investor->no_hp,
                        'pengelola'       => $this->klienAr->resto->investor->pengelola,
                        'no_hp_pengelola' => $this->klienAr->resto->investor->no_hp_pengelola,
                    ] : null,
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
            'submitted_at'               => $this->submitted_at?->toIso8601String(),
            'submitted_by'               => $this->submitted_by,
            'submitted_by_name'          => $this->whenLoaded('submittedBy', fn() => $this->submittedBy?->username),
            'approved_at'                => $this->approved_at?->toIso8601String(),
            'approved_by'                => $this->approved_by,
            'approved_by_name'           => $this->whenLoaded('approvedBy', fn() => $this->approvedBy?->username),
            'rejected_at'                => $this->rejected_at?->toIso8601String(),
            'rejected_by'                => $this->rejected_by,
            'rejected_by_name'           => $this->whenLoaded('rejectedBy', fn() => $this->rejectedBy?->username),
            'is_opening_balance'         => $this->is_opening_balance,
            'keterangan'                 => $this->keterangan,
            'items'                      => $this->whenLoaded('items', fn() =>
                InvoiceItemResource::collection($this->items)
            ),
            'opening_balance_details'    => $this->when(
                $this->is_opening_balance && $this->relationLoaded('openingBalanceDetails'),
                fn() => OpeningBalanceDetailResource::collection($this->openingBalanceDetails)
            ),
            'pembayarans'                => $this->whenLoaded('pembayarans', fn() =>
                $this->pembayarans->map(fn($p) => [
                    'id'                  => $p->id,
                    'tanggal_pembayaran'  => $p->tanggal_pembayaran?->format('d-m-Y'),
                    'jumlah_pembayaran'   => (float) $p->jumlah_pembayaran,
                    'metode_pembayaran'   => $p->metode_pembayaran,
                    'no_referensi'        => $p->no_referensi,
                    'keterangan'          => $p->keterangan,
                    'created_by_name'     => $p->relationLoaded('createdBy') ? $p->createdBy?->username : null,
                    'created_at'          => $p->created_at?->toIso8601String(),
                ])
            ),
            'approval_logs'              => $this->whenLoaded('approvalLogs', fn() =>
                $this->approvalLogs->map(fn($log) => [
                    'id'         => $log->id,
                    'action'     => $log->action,
                    'note'       => $log->note,
                    'actor_id'   => $log->actor_id,
                    'actor_name' => $log->relationLoaded('actor') ? $log->actor?->username : null,
                    'created_at' => $log->created_at?->toIso8601String(),
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
            'share_url'                  => $this->isApprovedForFinanceFlow() && $this->prepared_token
                ? url('/api/v1/invoices/public/' . $this->prepared_token)
                : null,
            'created_at'                 => $this->created_at?->toIso8601String(),
            'updated_at'                 => $this->updated_at?->toIso8601String(),
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
