<?php

namespace App\Domain\Master\Resto\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRestoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nama_resto'    => ['required', 'string', 'max:150'],
            'perusahaan_id' => ['nullable', 'integer', 'exists:tb_perusahaan,id'],
            'brand_id'      => ['nullable', 'integer', 'exists:tb_brand,id'],
            'investor_id'   => ['nullable', 'integer', 'exists:tb_investor,id'],
            'karyawan_id'   => ['nullable', 'integer', 'exists:tb_karyawan,id'],
            'area'          => ['nullable', 'string', 'max:100'],
            'kota'          => ['nullable', 'string', 'max:100'],
            'alamat'        => ['nullable', 'string'],
            'no_telp'       => ['nullable', 'string', 'max:20'],
            'tgl_aktif'     => ['nullable', 'date'],
            'keterangan'    => ['nullable', 'string'],
            'status'        => ['nullable', 'boolean'],
        ];
    }
}
