<?php

namespace App\Domain\Finance\TagihanAp\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTagihanApRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'no_tagihan'          => [
                'nullable', 'string', 'max:50',
                $id
                    ? Rule::unique('tb_tagihan_ap', 'no_tagihan')->ignore($id)
                    : 'unique:tb_tagihan_ap,no_tagihan',
            ],
            'no_invoice_vendor'   => ['nullable', 'string', 'max:255'],
            'vendor_ap_id'        => ['required', 'integer', 'exists:tb_vendor_ap,id'],
            'tanggal_tagihan'     => ['required', 'date'],
            'tanggal_jatuh_tempo' => ['nullable', 'date'],
            'no_po'               => ['nullable', 'string', 'max:255'],
            'no_terima_barang'    => ['nullable', 'string', 'max:255'],
            'ppn_masukan'         => ['nullable', 'numeric', 'min:0'],
            'pph23'               => ['nullable', 'numeric', 'min:0'],
            'keterangan'          => ['nullable', 'string'],

            'items'                    => ['required', 'array', 'min:1'],
            'items.*.barang_id'        => ['nullable', 'integer', 'exists:tb_barang,id'],
            'items.*.kode_barang'      => ['nullable', 'string', 'max:50'],
            'items.*.nama_barang'      => ['required', 'string', 'max:255'],
            'items.*.qty'              => ['required', 'numeric', 'min:0.001'],
            'items.*.satuan'           => ['nullable', 'string', 'max:20'],
            'items.*.harga_satuan'     => ['required', 'numeric', 'min:0'],
            'items.*.keterangan'       => ['nullable', 'string', 'max:500'],
        ];
    }
}
