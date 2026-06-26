<?php

namespace App\Domain\Master\Barang\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBarangRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'kode_barang' => ['required', 'string', 'max:50', 'unique:tb_barang,kode_barang'],
            'nama_barang' => ['required', 'string', 'max:150'],
            'brand_id'    => ['required', 'integer', 'exists:tb_brand,id'],
            'spesifikasi'   => ['nullable', 'string'],
            'keterangan'    => ['nullable', 'string'],
            'status'        => ['nullable', 'boolean'],
        ];
    }
}
