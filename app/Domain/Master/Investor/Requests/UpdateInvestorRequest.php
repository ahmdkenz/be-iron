<?php

namespace App\Domain\Master\Investor\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvestorRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('investor');

        return [
            'nama_investor'   => ['required', 'string', 'max:150'],
            'ktp'             => ['nullable', 'string', 'max:20', "unique:tb_investor,ktp,{$id}"],
            'npwp'            => ['nullable', 'string', 'max:20', "unique:tb_investor,npwp,{$id}"],
            'no_hp'           => ['nullable', 'string', 'max:20'],
            'pengelola'       => ['nullable', 'string', 'max:150'],
            'no_hp_pengelola' => ['nullable', 'string', 'max:20'],
            'alamat'          => ['nullable', 'string'],
            'keterangan'      => ['nullable', 'string'],
            'status'          => ['nullable', 'boolean'],
        ];
    }
}
