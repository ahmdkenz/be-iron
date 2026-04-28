<?php

namespace App\Domain\Master\Brand\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('brand');

        return [
            'kode_brand' => ['required', 'string', 'max:50', "unique:tb_brand,kode_brand,{$id}"],
            'nama_brand' => ['required', 'string', 'max:150'],
            'keterangan' => ['nullable', 'string'],
            'status'     => ['nullable', 'boolean'],
        ];
    }
}
