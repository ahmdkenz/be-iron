<?php

namespace App\Domain\Finance\PembayaranAp\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class PembayaranApResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $itemsArray = $this->whenLoaded('items') instanceof \Illuminate\Support\Collection
            ? $this->items
            : collect();
        $firstTagihan = $itemsArray->first()?->tagihanAp;
        $isSingle     = $itemsArray->count() === 1;

        return [
            'id'                       => $this->id,
            'jumlah_tagihan'           => $itemsArray->count(),
            'items'                    => $this->whenLoaded('items', fn() => $itemsArray->map(fn($item) => [
                'id'                  => $item->id,
                'tagihan_ap_id'       => $item->tagihan_ap_id,
                'no_tagihan'          => $item->tagihanAp?->no_tagihan,
                'no_invoice_vendor'   => $item->tagihanAp?->no_invoice_vendor,
                'vendor_ap_id'        => $item->vendor_ap_id,
                'vendor'              => $item->tagihanAp?->vendorAp?->nama_vendor ?? $item->vendorAp?->nama_vendor,
                'kode_vendor'         => $item->tagihanAp?->vendorAp?->kode_vendor ?? $item->vendorAp?->kode_vendor,
                'jumlah_dialokasikan' => (float) $item->jumlah_dialokasikan,
                'sisa_sebelum'        => (float) $item->sisa_sebelum,
                'sisa_sesudah'        => (float) $item->sisa_sesudah,
            ])->values()),
            // Field tunggal di bawah dipertahankan (derivasi dari tagihan item
            // pertama saat voucher cuma berisi 1 tagihan) supaya konsumen lama
            // (mis. tombol "Catat Bayar" 1 tagihan) tidak perlu berubah.
            'tagihan_ap_id'            => $isSingle ? $firstTagihan?->id : null,
            'no_tagihan'               => $isSingle ? $firstTagihan?->no_tagihan : null,
            'tanggal_jatuh_tempo'      => $isSingle ? $firstTagihan?->tanggal_jatuh_tempo?->format('d-m-Y') : null,
            'tagihan_status'           => $isSingle ? $firstTagihan?->status : null,
            'total_tagihan'            => $isSingle ? (float) ($firstTagihan?->total_tagihan ?? 0) : null,
            'total_pembayaran_tagihan' => $isSingle ? (float) ($firstTagihan?->total_pembayaran ?? 0) : null,
            'sisa_tagihan'             => $isSingle ? (float) ($firstTagihan?->sisa_tagihan ?? 0) : null,
            'vendor_ap_id'             => $isSingle ? $firstTagihan?->vendor_ap_id : null,
            'kode_vendor'              => $isSingle ? $firstTagihan?->vendorAp?->kode_vendor : null,
            'vendor'                   => $isSingle ? $firstTagihan?->vendorAp?->nama_vendor : null,
            'perusahaan'               => $isSingle ? $firstTagihan?->perusahaan?->nama_singkatan_perusahaan : null,
            'pic_ap'                   => $isSingle ? $firstTagihan?->karyawan?->nama_karyawan : null,
            'tanggal_pembayaran'       => $this->tanggal_pembayaran?->format('d-m-Y'),
            'jumlah_pembayaran'        => (float) $this->jumlah_pembayaran,
            'metode_pembayaran'        => $this->metode_pembayaran,
            'kategori_voucher'         => $this->kategori_voucher,
            'kategori_voucher_label'   => match ($this->kategori_voucher) {
                'BB'    => 'Bahan Baku',
                'NBB'   => 'Non Bahan Baku',
                default => 'Belum Dikategorikan',
            },
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
