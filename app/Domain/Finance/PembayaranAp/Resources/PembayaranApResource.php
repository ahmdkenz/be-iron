<?php

namespace App\Domain\Finance\PembayaranAp\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class PembayaranApResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->id,
            'tagihan_ap_id'            => $this->tagihan_ap_id,
            'no_tagihan'               => $this->whenLoaded('tagihanAp', fn() => $this->tagihanAp?->no_tagihan),
            'tanggal_jatuh_tempo'      => $this->whenLoaded('tagihanAp', fn() => $this->tagihanAp?->tanggal_jatuh_tempo?->format('d-m-Y')),
            'tagihan_status'           => $this->whenLoaded('tagihanAp', fn() => $this->tagihanAp?->status),
            'total_tagihan'            => $this->whenLoaded('tagihanAp', fn() => (float) ($this->tagihanAp?->total_tagihan ?? 0)),
            'total_pembayaran_tagihan' => $this->whenLoaded('tagihanAp', fn() => (float) ($this->tagihanAp?->total_pembayaran ?? 0)),
            'sisa_tagihan'             => $this->whenLoaded('tagihanAp', fn() => (float) ($this->tagihanAp?->sisa_tagihan ?? 0)),
            'vendor_ap_id'             => $this->whenLoaded('tagihanAp', fn() => $this->tagihanAp?->vendor_ap_id),
            'kode_vendor'              => $this->whenLoaded('tagihanAp', fn() => $this->tagihanAp?->vendorAp?->kode_vendor),
            'vendor'                   => $this->whenLoaded('tagihanAp', fn() => $this->tagihanAp?->vendorAp?->nama_vendor),
            'perusahaan'               => $this->whenLoaded('tagihanAp', fn() => $this->tagihanAp?->perusahaan?->nama_singkatan_perusahaan),
            'pic_ap'                   => $this->whenLoaded('tagihanAp', fn() => $this->tagihanAp?->karyawan?->nama_karyawan),
            'tanggal_pembayaran'       => $this->tanggal_pembayaran?->format('d-m-Y'),
            'jumlah_pembayaran'        => (float) $this->jumlah_pembayaran,
            'metode_pembayaran'        => $this->metode_pembayaran,
            'no_referensi'             => $this->no_referensi,
            'keterangan'               => $this->keterangan,
            'bukti_file_name'          => $this->bukti_file_name,
            'bukti_file_size'          => $this->bukti_file_size,
            'bukti_mime_type'          => $this->bukti_mime_type,
            'bukti_uploaded_at'        => $this->bukti_uploaded_at?->format('d-m-Y H:i'),
            'bukti_url'                => $this->bukti_path
                ? URL::temporarySignedRoute(
                    'pembayaran-ap.public-bukti', now()->addDays(30), ['pembayaran' => $this->id]
                )
                : null,
            'created_by'               => $this->created_by,
            'created_by_name'          => $this->whenLoaded('createdBy', fn() => $this->createdBy?->karyawan?->nama_karyawan ?? $this->createdBy?->username),
            'created_at'               => $this->created_at?->format('d-m-Y H:i'),
        ];
    }
}
