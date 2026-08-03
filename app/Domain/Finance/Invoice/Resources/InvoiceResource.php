<?php

namespace App\Domain\Finance\Invoice\Resources;

use App\Models\User;
use App\Support\Helpers\RoleHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;
use App\Domain\Finance\Invoice\Resources\OpeningBalanceDetailResource;
use App\Models\EndingBalance;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isLocked = (bool) $this->tanggal_invoice
            && EndingBalance::where('klien_ar_id', $this->klien_ar_id)
                ->where('status', 'LOCKED')
                ->where('periode_awal', '<=', $this->tanggal_invoice)
                ->where('periode_akhir', '>=', $this->tanggal_invoice)
                ->exists();

        return [
            'id'                         => $this->id,
            'no_invoice'                 => $this->no_invoice,
            'tanggal_invoice'            => $this->tanggal_invoice?->format('d-m-Y'),
            'klien_ar_id'                => $this->klien_ar_id,
            'resto_id'                   => $this->resto_id,
            'resto'                      => $this->whenLoaded('resto', fn() => $this->resto ? [
                'id'         => $this->resto->id,
                'kode_resto' => $this->resto->kode_resto,
                'nama_resto' => $this->resto->nama_resto,
            ] : null),
            'klien_ar'                   => $this->whenLoaded('klienAr', fn() => $this->klienAr ? [
                'id'           => $this->klienAr->id,
                'kode_klien'   => $this->klienAr->kode_klien,
                'nama_klien'   => $this->klienAr->nama_klien,
                'tipe_klien'   => $this->klienAr->tipe_klien,
                'perusahaan_id'=> $this->klienAr->perusahaan_id,
                'perusahaan'   => $this->klienAr->relationLoaded('perusahaan') && $this->klienAr->perusahaan ? [
                    'id'              => $this->klienAr->perusahaan->id,
                    'nama_perusahaan' => $this->klienAr->perusahaan->nama_perusahaan,
                    'no_npwp'         => $this->klienAr->perusahaan->no_npwp,
                ] : null,
                'no_npwp'      => $this->klienAr->no_npwp,
                'no_wa'        => $this->klienAr->resolveContactPhone(),
                'client_npwp'  => $this->resolveKlienNpwp($this->klienAr),
                'karyawan_ar'  => $this->klienAr->relationLoaded('karyawanAr') ? [
                    'id'           => $this->klienAr->karyawanAr?->id,
                    'nik'          => $this->klienAr->karyawanAr?->nik,
                    'nama_karyawan'=> $this->klienAr->karyawanAr?->nama_karyawan,
                    'perusahaan'   => $this->klienAr->karyawanAr?->relationLoaded('perusahaan') && $this->klienAr->karyawanAr?->perusahaan ? [
                        'id'                        => $this->klienAr->karyawanAr->perusahaan->id,
                        'nama_singkatan_perusahaan' => $this->klienAr->karyawanAr->perusahaan->nama_singkatan_perusahaan,
                        'nama_perusahaan'           => $this->klienAr->karyawanAr->perusahaan->nama_perusahaan,
                    ] : null,
                ] : null,
                'resto'        => $this->klienAr->relationLoaded('resto') && $this->klienAr->resto ? [
                    'id'         => $this->klienAr->resto->id,
                    'kode_resto' => $this->klienAr->resto->kode_resto,
                    'nama_resto' => $this->klienAr->resto->nama_resto,
                    'investor'   => $this->klienAr->resto->relationLoaded('investor') && $this->klienAr->resto->investor ? [
                        'id'              => $this->klienAr->resto->investor->id,
                        'nama_investor'   => $this->klienAr->resto->investor->nama_investor,
                        'npwp'            => $this->klienAr->resto->investor->npwp,
                        'no_hp'           => $this->klienAr->resto->investor->no_hp,
                        'pengelola'       => $this->klienAr->resto->investor->pengelola,
                        'no_hp_pengelola' => $this->klienAr->resto->investor->no_hp_pengelola,
                    ] : null,
                ] : null,
            ] : null),
            'entitas_penagih'            => $this->resolveEntitasPenagihPayload(),
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
                'perusahaan'   => $this->karyawan->relationLoaded('perusahaan') && $this->karyawan->perusahaan ? [
                    'id'              => $this->karyawan->perusahaan->id,
                    'nama_perusahaan' => $this->karyawan->perusahaan->nama_perusahaan,
                ] : null,
            ]),
            'no_surat_jalan'             => $this->no_surat_jalan,
            'subtotal'                   => (float) $this->subtotal,
            'tagihan_periode_sebelumnya' => (float) $this->tagihan_periode_sebelumnya,
            'total_tagihan'              => (float) $this->total_tagihan,
            'total_pembayaran'           => (float) $this->total_pembayaran,
            'total_penyesuaian'          => (float) $this->total_penyesuaian,
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
            'last_decision_note'         => $this->whenLoaded('lastDecisionLog', fn() => $this->lastDecisionLog?->note),
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
            'koreksi'                    => $this->whenLoaded('endingBalanceKoreksi', fn() =>
                $this->endingBalanceKoreksi->map(fn($k) => [
                    'id'                  => $k->id,
                    'tipe'                => $k->tipe,
                    'no_dokumen'          => $k->no_dokumen,
                    'nilai_koreksi'       => (float) $k->nilai_koreksi,
                    'alasan_koreksi'      => $k->alasan_koreksi,
                    'status'              => $k->status,
                    'submitted_by'        => $k->relationLoaded('submittedBy') ? $k->submittedBy?->username : null,
                    'submitted_at'        => $k->submitted_at?->toIso8601String(),
                    'spv'                 => $k->relationLoaded('spv') ? $k->spv?->username : null,
                    'manager'             => $k->relationLoaded('manager') ? $k->manager?->username : null,
                    'manager_actioned_at' => $k->manager_actioned_at?->toIso8601String(),
                ])->values()
            ),
            'created_by'                 => $this->created_by,
            'created_by_name'            => $this->whenLoaded('createdBy', fn() => $this->createdBy?->username),
            'updated_by'                 => $this->updated_by,
            'updated_by_name'            => $this->whenLoaded('updatedBy', fn() => $this->updatedBy?->username),
            'is_eb_locked'               => $isLocked,
            'can_approve'                => $this->canApprove($request->user()),
            'can_reject'                 => $this->canReject($request->user()),
            'can_edit'                   => $this->canEdit($request->user(), $isLocked),
            'can_submit'                 => $this->canSubmit($request->user()),
            'can_record_payment'         => $this->isApprovedForFinanceFlow(),
            'can_print'                  => $this->isApprovedForFinanceFlow(),
            'share_url'                  => $this->isApprovedForFinanceFlow() && $this->prepared_token
                ? URL::temporarySignedRoute(
                    'invoice.public-print', now()->addDays(30), ['token' => $this->prepared_token]
                )
                : null,
            'created_at'                 => $this->created_at?->toIso8601String(),
            'updated_at'                 => $this->updated_at?->toIso8601String(),
        ];
    }

    private function resolveEntitasPenagihPayload(): ?array
    {
        $perusahaan = $this->resolveEntitasPenagih();

        if (!$perusahaan) {
            return null;
        }

        return [
            'id'                        => $perusahaan->id,
            'nama_perusahaan'           => $perusahaan->nama_perusahaan,
            'nama_singkatan_perusahaan' => $perusahaan->nama_singkatan_perusahaan,
            'source'                    => $this->resolveEntitasPenagihSource(),
        ];
    }

    private function resolveKlienNpwp(\App\Models\KlienAr $klien): ?string
    {
        if ($klien->tipe_klien === 'RESTO') {
            $fromInvestor = $klien->relationLoaded('resto') && $klien->resto
                && $klien->resto->relationLoaded('investor') && $klien->resto->investor
                ? $klien->resto->investor->npwp : null;
            return $fromInvestor ?: ($klien->no_npwp ?: null);
        }
        if ($klien->tipe_klien === 'PT') {
            $fromPerusahaan = $klien->relationLoaded('perusahaan') && $klien->perusahaan
                ? $klien->perusahaan->no_npwp : null;
            return $fromPerusahaan ?: ($klien->no_npwp ?: null);
        }
        return $klien->no_npwp ?: null;
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

    private function canEdit(?User $user, bool $isLocked = false): bool
    {
        return $this->is_opening_balance === true
            && $this->status === 'DRAFT'
            && $this->approval_status === 'REJECTED'
            && !$isLocked
            && RoleHelper::canOperateOpeningBalance($user);
    }

    private function canSubmit(?User $user): bool
    {
        return $this->canBeResubmitted()
            && RoleHelper::canOperateOpeningBalance($user);
    }
}
