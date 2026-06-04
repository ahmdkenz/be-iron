<?php

namespace App\Domain\Finance\Invoice\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'no_invoice'        => ['required', 'string', 'max:100', Rule::unique('tb_invoice', 'no_invoice')->ignore($this->route('invoice'))],
            'klien_ar_id'       => ['required', 'integer', 'exists:tb_klien_ar,id'],
            'resto_id'          => ['nullable', 'integer', 'exists:tb_resto,id'],
            'tanggal_invoice'      => ['required', 'date'],
            'tanggal_jatuh_tempo'  => ['nullable', 'date'],
            'periode_awal'         => ['required', 'date'],
            'periode_akhir'     => ['required', 'date', 'after_or_equal:periode_awal'],
            'no_surat_jalan'    => ['nullable', 'string', 'max:50'],
            'keterangan'        => ['nullable', 'string'],
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
