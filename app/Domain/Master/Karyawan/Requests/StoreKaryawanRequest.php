<?php

namespace App\Domain\Master\Karyawan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreKaryawanRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nik'           => ['required', 'string', 'max:30', 'unique:tb_karyawan,nik'],
            'nama_karyawan' => ['required', 'string', 'max:100'],
            'perusahaan_id' => ['required', 'integer', 'exists:tb_perusahaan,id'],
            'keterangan'    => ['nullable', 'string'],
            'status'        => ['nullable', 'boolean'],
        ];
    }
}
