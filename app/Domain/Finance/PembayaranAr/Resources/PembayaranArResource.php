<?php

namespace App\Domain\Finance\PembayaranAr\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class PembayaranArResource extends JsonResource
{
    private function resolveJenis(): string
    {
        $ref = $this->no_referensi ?? '';
        return match (true) {
            str_contains($ref, '/PDM-') => 'PDM',
            str_contains($ref, '/ALO-') => 'ALO',
            default                     => 'REGULER',
        };
    }

    public function toArray(Request $request): array
    {
        $bankDetail = $this->relationLoaded('bankStatementDetail') ? $this->bankStatementDetail : null;
        if (!$bankDetail && $this->relationLoaded('sumberPembayaran')) {
            $bankDetail = $this->sumberPembayaran?->bankStatementDetail;
        }

        return [
            'id'                    => $this->id,
            'invoice_id'            => $this->invoice_id,
            'no_invoice'            => $this->whenLoaded('invoice', fn() => $this->invoice?->no_invoice),
            'tanggal_invoice'       => $this->whenLoaded('invoice', fn() => $this->invoice?->tanggal_invoice?->format('d-m-Y')),
            'tanggal_jatuh_tempo'   => $this->whenLoaded('invoice', fn() => $this->invoice?->tanggal_jatuh_tempo?->format('d-m-Y')),
            'invoice_status'        => $this->whenLoaded('invoice', fn() => $this->invoice?->status),
            'total_tagihan'         => $this->whenLoaded('invoice', fn() => (float) ($this->invoice?->total_tagihan ?? 0)),
            'total_pembayaran_invoice' => $this->whenLoaded('invoice', fn() => (float) ($this->invoice?->total_pembayaran ?? 0)),
            'sisa_tagihan'          => $this->whenLoaded('invoice', fn() => (float) ($this->invoice?->sisa_tagihan ?? 0)),
            'klien_ar_id'           => $this->whenLoaded('invoice', fn() => $this->invoice?->klien_ar_id),
            'kode_klien'            => $this->whenLoaded('invoice', fn() => $this->invoice?->klienAr?->kode_klien),
            'klien'                 => $this->whenLoaded('invoice', fn() => $this->invoice?->klienAr?->nama_klien),
            'tipe_klien'            => $this->whenLoaded('invoice', fn() => $this->invoice?->klienAr?->tipe_klien),
            'perusahaan'            => $this->whenLoaded('invoice', fn() => $this->invoice?->perusahaan?->nama_singkatan_perusahaan),
            'pic_ar'                => $this->whenLoaded('invoice', fn() => $this->invoice?->karyawan?->nama_karyawan),
            'tanggal_pembayaran'    => $this->tanggal_pembayaran?->format('d-m-Y'),
            'jumlah_pembayaran'     => (float) $this->jumlah_pembayaran,
            'metode_pembayaran'     => $this->metode_pembayaran,
            'no_referensi'          => $this->no_referensi,
            'jenis'                 => $this->resolveJenis(),
            'keterangan'            => $this->keterangan,
            'bukti_file_name'       => $this->bukti_file_name,
            'bukti_file_size'       => $this->bukti_file_size,
            'bukti_mime_type'       => $this->bukti_mime_type,
            'bukti_uploaded_at'     => $this->bukti_uploaded_at?->format('d-m-Y H:i'),
            'bukti_url'             => $this->bukti_path
                ? URL::temporarySignedRoute(
                    'pembayaran.public-bukti', now()->addDays(30), ['pembayaran' => $this->id]
                )
                : ($this->bukti_gdrive_file_id
                    ? 'https://drive.google.com/file/d/' . $this->bukti_gdrive_file_id . '/view'
                    : null),
            'status_rekonsiliasi'   => $bankDetail?->status_cocok,
            'tanggal_rekonsiliasi'  => $bankDetail?->tanggal?->format('d-m-Y'),
            'no_ref_bank'           => $bankDetail?->no_referensi,
            'nominal_bank'          => $bankDetail ? (float) $bankDetail->kredit : null,
            'created_by'            => $this->created_by,
            'created_by_name'       => $this->whenLoaded('createdBy', fn() => $this->createdBy?->karyawan?->nama_karyawan ?? $this->createdBy?->username),
            'created_at'            => $this->created_at?->format('d-m-Y H:i'),
        ];
    }
}
