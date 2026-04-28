<?php

namespace App\Domain\Master\Perusahaan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePerusahaanRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('perusahaan');

        return [
            'kode_perusahaan'           => ['required', 'string', 'max:20', "unique:tb_perusahaan,kode_perusahaan,{$id}"],
            'nama_perusahaan'           => ['required', 'string', 'max:100'],
            'nama_singkatan_perusahaan' => ['required', 'string', 'max:20'],
            'alamat'                    => ['nullable', 'string', 'max:255'],
            'kota'                      => ['nullable', 'string', 'max:100'],
            'kode_pos'                  => ['nullable', 'string', 'max:10'],
            'no_telp'                   => ['nullable', 'string', 'max:30'],
            'email'                     => ['nullable', 'email', 'max:100'],
            'no_npwp'                   => ['nullable', 'string', 'max:30'],
            'keterangan'                => ['nullable', 'string'],
            'status'                    => ['nullable', 'boolean'],
        ];
    }
}
