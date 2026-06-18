<?php

namespace App\Domain\Finance\PembayaranAr\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
        return [
            'id'                    => $this->id,
            'invoice_id'            => $this->invoice_id,
            'no_invoice'            => $this->whenLoaded('invoice', fn() => $this->invoice?->no_invoice),
            'klien'                 => $this->whenLoaded('invoice', fn() => $this->invoice?->klienAr?->nama_klien),
            'perusahaan'            => $this->whenLoaded('invoice', fn() => $this->invoice?->perusahaan?->nama_singkatan_perusahaan),
            'tanggal_pembayaran'    => $this->tanggal_pembayaran?->format('d-m-Y'),
            'jumlah_pembayaran'     => (float) $this->jumlah_pembayaran,
            'metode_pembayaran'     => $this->metode_pembayaran,
            'no_referensi'          => $this->no_referensi,
            'jenis'                 => $this->resolveJenis(),
            'keterangan'            => $this->keterangan,
            'bukti_gdrive_file_id'  => $this->bukti_gdrive_file_id,
            'bukti_file_name'       => $this->bukti_file_name,
            'bukti_file_size'       => $this->bukti_file_size,
            'bukti_mime_type'       => $this->bukti_mime_type,
            'bukti_uploaded_at'     => $this->bukti_uploaded_at?->format('d-m-Y H:i'),
            'bukti_gdrive_url'      => $this->bukti_gdrive_file_id
                ? 'https://drive.google.com/file/d/' . $this->bukti_gdrive_file_id . '/view'
                : null,
            'created_by'            => $this->created_by,
            'created_by_name'       => $this->whenLoaded('createdBy', fn() => $this->createdBy?->karyawan?->nama_karyawan ?? $this->createdBy?->username),
            'created_at'            => $this->created_at?->format('d-m-Y H:i'),
        ];
    }
}
