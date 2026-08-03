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

        // Multi Payment: invoice_id header selalu NULL, alokasi per invoice hidup
        // di tb_pembayaran_ar_items — tanpa fallback ini kolom klien/perusahaan/
        // PIC/no_invoice selalu kosong walau baris-nya sudah tampil di laporan
        // (lihat RiwayatPembayaranService). Item pertama dipakai sebagai wakil
        // klien/entitas/PIC karena createMultiPayment() mewajibkan semua invoice
        // dalam 1 Multi Payment berasal dari entitas penagih yang sama.
        $items          = $this->relationLoaded('items') ? $this->items : collect();
        $isMultiPayment = $this->invoice_id === null && $items->isNotEmpty();
        $primaryInvoice = $this->invoice ?? $items->first()?->invoice;

        return [
            'id'                    => $this->id,
            'invoice_id'            => $this->invoice_id,
            'no_invoice'            => $isMultiPayment ? null : $this->whenLoaded('invoice', fn() => $this->invoice?->no_invoice),
            'tanggal_invoice'       => $isMultiPayment ? null : $this->whenLoaded('invoice', fn() => $this->invoice?->tanggal_invoice?->format('d-m-Y')),
            'tanggal_jatuh_tempo'   => $isMultiPayment ? null : $this->whenLoaded('invoice', fn() => $this->invoice?->tanggal_jatuh_tempo?->format('d-m-Y')),
            'invoice_status'        => $isMultiPayment ? null : $this->whenLoaded('invoice', fn() => $this->invoice?->status),
            'total_tagihan'         => $isMultiPayment ? null : $this->whenLoaded('invoice', fn() => (float) ($this->invoice?->total_tagihan ?? 0)),
            'total_pembayaran_invoice' => $isMultiPayment ? null : $this->whenLoaded('invoice', fn() => (float) ($this->invoice?->total_pembayaran ?? 0)),
            'sisa_tagihan'          => $isMultiPayment ? null : $this->whenLoaded('invoice', fn() => (float) ($this->invoice?->sisa_tagihan ?? 0)),
            'klien_ar_id'           => $primaryInvoice?->klien_ar_id,
            'kode_klien'            => $primaryInvoice?->klienAr?->kode_klien,
            'klien'                 => $primaryInvoice?->klienAr?->nama_klien,
            'tipe_klien'            => $primaryInvoice?->klienAr?->tipe_klien,
            'perusahaan'            => $primaryInvoice?->perusahaan?->nama_singkatan_perusahaan,
            'pic_ar'                => $primaryInvoice?->karyawan?->nama_karyawan,
            'is_multi_payment'            => $isMultiPayment,
            'multi_payment_klien_count'   => $isMultiPayment ? $items->pluck('klien_ar_id')->unique()->count() : null,
            'multi_payment_invoices'      => $isMultiPayment
                ? $items->map(fn($item) => [
                    'invoice_id' => $item->invoice_id,
                    'no_invoice' => $item->invoice?->no_invoice,
                    'jumlah'     => (float) $item->jumlah_dialokasikan,
                ])->values()->all()
                : null,
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
                : null,
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
