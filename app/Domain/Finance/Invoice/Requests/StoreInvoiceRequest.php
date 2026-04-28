<?php

namespace App\Domain\Finance\Invoice\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'klien_ar_id'       => ['required', 'integer', 'exists:tb_klien_ar,id'],
            'tanggal_invoice'      => ['required', 'date'],
            'tanggal_jatuh_tempo'  => ['nullable', 'date', 'after_or_equal:tanggal_invoice'],
            'periode_awal'         => ['required', 'date'],
            'periode_akhir'     => ['required', 'date', 'after_or_equal:periode_awal'],
            'no_surat_jalan'    => ['nullable', 'string', 'max:50'],
            'keterangan'        => ['nullable', 'string'],
            'status'            => ['nullable', 'in:DRAFT,TERKIRIM'],
            'items'             => ['required', 'array', 'min:1'],
            'items.*.barang_id'   => ['nullable', 'integer', 'exists:tb_barang,id'],
            'items.*.nama_barang' => ['required', 'string', 'max:150'],
            'items.*.qty'         => ['required', 'numeric', 'min:0.001'],
            'items.*.satuan'      => ['nullable', 'string', 'max:20'],
            'items.*.harga_satuan'=> ['required', 'numeric', 'min:0'],
            'items.*.keterangan'  => ['nullable', 'string', 'max:255'],
        ];
    }
}
