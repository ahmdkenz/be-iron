<?php

namespace App\Domain\Finance\KlienAr\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreKlienArRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'kode_klien'    => ['nullable', 'string', 'max:20'],
            'nama_klien'    => ['required', 'string', 'max:150'],
            'alias'         => ['nullable', 'string', 'max:100'],
            'tipe_klien'    => ['required', 'in:PT,RESTO,STOKIS,MITRA'],
            'tipe_outlet'   => ['nullable', 'string', 'max:50'],
            'stokis_area'   => ['nullable', 'string', 'max:100'],
            'no_npwp'       => ['nullable', 'string', 'max:30'],
            'perusahaan_id' => ['required', 'integer', 'exists:tb_perusahaan,id'],
            'karyawan_ar_id'=> ['required', 'integer', 'exists:tb_karyawan,id'],
            'resto_id'      => ['nullable', 'integer', 'exists:tb_resto,id'],
            'status'        => ['nullable', 'boolean'],
        ];
    }
}
