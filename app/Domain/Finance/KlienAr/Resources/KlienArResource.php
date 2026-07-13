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
            'display_label'    => $this->resolveDisplayLabel(),
            'display_subtitle' => $this->resolveDisplaySubtitle(),
            'tipe_klien'     => $this->tipe_klien,
            'no_npwp'        => $this->no_npwp,
            'no_wa'          => $this->no_wa,
            'client_npwp'          => $this->resolveClientNpwp(),
            'client_contact_phone' => $this->resolveClientContactPhone(),
            'client_contact_source'=> $this->resolveClientContactSource(),
            'perusahaan_id'  => $this->perusahaan_id,
            'perusahaan'     => $this->whenLoaded('perusahaan', fn() => [
                'id'                        => $this->perusahaan->id,
                'kode_perusahaan'           => $this->perusahaan->kode_perusahaan,
                'nama_singkatan_perusahaan' => $this->perusahaan->nama_singkatan_perusahaan,
                'nama_perusahaan'           => $this->perusahaan->nama_perusahaan,
                'no_npwp'                   => $this->perusahaan->no_npwp,
                'no_telp'                   => $this->perusahaan->no_telp,
            ]),
            'karyawan_ar_id' => $this->karyawan_ar_id,
            'karyawan_ar'    => $this->whenLoaded('karyawanAr', fn() => [
                'id'            => $this->karyawanAr->id,
                'nik'           => $this->karyawanAr->nik,
                'nama_karyawan' => $this->karyawanAr->nama_karyawan,
                'perusahaan'    => $this->karyawanAr->relationLoaded('perusahaan') && $this->karyawanAr->perusahaan ? [
                    'id'                        => $this->karyawanAr->perusahaan->id,
                    'nama_perusahaan'           => $this->karyawanAr->perusahaan->nama_perusahaan,
                    'nama_singkatan_perusahaan' => $this->karyawanAr->perusahaan->nama_singkatan_perusahaan,
                ] : null,
            ]),
            'resto_id'       => $this->resto_id,
            'resto'          => $this->whenLoaded('resto', fn() => $this->resto ? [
                'id'         => $this->resto->id,
                'kode_resto' => $this->resto->kode_resto,
                'nama_resto' => $this->resto->nama_resto,
                'investor'   => $this->resto->relationLoaded('investor') && $this->resto->investor ? [
                    'id'              => $this->resto->investor->id,
                    'nama_investor'   => $this->resto->investor->nama_investor,
                    'npwp'            => $this->resto->investor->npwp,
                    'no_hp'           => $this->resto->investor->no_hp,
                    'pengelola'       => $this->resto->investor->pengelola,
                    'no_hp_pengelola' => $this->resto->investor->no_hp_pengelola,
                ] : null,
            ] : null),
            'status'          => $this->status,
            'created_by'      => $this->created_by,
            'created_by_name' => $this->whenLoaded('createdBy', fn() => $this->createdBy?->username),
            'updated_by'      => $this->updated_by,
            'updated_by_name' => $this->whenLoaded('updatedBy', fn() => $this->updatedBy?->username),
            'created_at'      => $this->created_at?->setTimezone('Asia/Jakarta')->format('d-m-Y H:i'),
            'updated_at'      => $this->updated_at?->setTimezone('Asia/Jakarta')->format('d-m-Y H:i'),
        ];
    }

    private function resolveDisplayLabel(): string
    {
        if ($this->tipe_klien === 'RESTO') {
            $resto      = $this->relationLoaded('resto') ? $this->resto : null;
            $investor   = $resto && $resto->relationLoaded('investor') ? $resto->investor : null;
            $primaryName = $investor->nama_investor ?? $this->nama_klien;

            $label = $primaryName;
            if ($resto?->nama_resto) {
                $label .= " - {$resto->nama_resto}";
            }
            $kode = $resto->kode_resto ?? $this->kode_klien;
            if ($kode) {
                $label .= " ({$kode})";
            }

            return $label;
        }

        $label   = $this->nama_klien;
        $perusahaan = $this->relationLoaded('perusahaan') ? $this->perusahaan : null;
        $entitas = $perusahaan?->nama_singkatan_perusahaan ?: $perusahaan?->nama_perusahaan;
        if ($entitas) {
            $label .= " - {$entitas}";
        }
        if ($this->kode_klien) {
            $label .= " ({$this->kode_klien})";
        }

        return $label;
    }

    private function resolveDisplaySubtitle(): string
    {
        $parts = array_filter([
            $this->tipe_klien,
            $this->kode_klien,
            $this->relationLoaded('karyawanAr') && $this->karyawanAr
                ? "PIC: {$this->karyawanAr->nama_karyawan}" : null,
        ]);

        return implode(' • ', $parts);
    }

    private function resolveClientNpwp(): ?string
    {
        if ($this->tipe_klien === 'RESTO') {
            $fromInvestor = $this->relationLoaded('resto') && $this->resto
                && $this->resto->relationLoaded('investor') && $this->resto->investor
                ? $this->resto->investor->npwp : null;
            return $fromInvestor ?: ($this->no_npwp ?: null);
        }
        if ($this->tipe_klien === 'PT') {
            $fromPerusahaan = $this->relationLoaded('perusahaan') && $this->perusahaan
                ? $this->perusahaan->no_npwp : null;
            return $fromPerusahaan ?: ($this->no_npwp ?: null);
        }
        return $this->no_npwp ?: null;
    }

    private function resolveClientContactPhone(): ?string
    {
        if ($this->tipe_klien === 'RESTO') {
            $fromInvestor = $this->relationLoaded('resto') && $this->resto
                && $this->resto->relationLoaded('investor') && $this->resto->investor
                ? $this->resto->investor->no_hp : null;
            return $fromInvestor ?: ($this->no_wa ?: null);
        }
        if ($this->tipe_klien === 'PT') {
            $fromPerusahaan = $this->relationLoaded('perusahaan') && $this->perusahaan
                ? $this->perusahaan->no_telp : null;
            return $fromPerusahaan ?: ($this->no_wa ?: null);
        }
        return $this->no_wa ?: null;
    }

    private function resolveClientContactSource(): ?string
    {
        if ($this->tipe_klien === 'RESTO') {
            $investorNpwp = $this->relationLoaded('resto') && $this->resto
                && $this->resto->relationLoaded('investor') && $this->resto->investor
                ? $this->resto->investor->npwp : null;
            if ($investorNpwp) return 'investor';
            if ($this->no_npwp) return 'klien';
            return null;
        }
        if ($this->tipe_klien === 'PT') {
            $perNpwp = $this->relationLoaded('perusahaan') && $this->perusahaan
                ? $this->perusahaan->no_npwp : null;
            if ($perNpwp) return 'perusahaan';
            if ($this->no_npwp) return 'klien';
            return null;
        }
        return $this->no_npwp ? 'klien' : null;
    }
}
