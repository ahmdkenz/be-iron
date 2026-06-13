<?php

namespace App\Domain\Finance\KlienAr\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreKlienArRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'kode_klien'    => ['required', 'string', 'max:20'],
            'nama_klien'    => ['required', 'string', 'max:150'],
            'tipe_klien'    => ['nullable', 'in:PT,RESTO'],
            'no_npwp'       => ['nullable', 'string', 'max:30'],
            'no_wa'         => ['nullable', 'string', 'max:20'],
            'perusahaan_id' => ['nullable', 'integer', 'exists:tb_perusahaan,id'],
            'karyawan_ar_id'=> ['required', 'integer', 'exists:tb_karyawan,id'],
            'resto_id'      => ['nullable', 'integer', 'exists:tb_resto,id'],
            'status'        => ['nullable', 'boolean'],
        ];
    }
}
