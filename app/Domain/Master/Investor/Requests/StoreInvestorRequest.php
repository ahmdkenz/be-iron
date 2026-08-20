<?php

namespace App\Domain\Master\Investor\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvestorRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'nama_investor'   => ['required', 'string', 'max:150'],
            'ktp'             => ['nullable', 'string', 'max:20'],
            'npwp'            => ['nullable', 'string', 'max:30'],
            'no_hp'           => ['nullable', 'string', 'max:20'],
            'pengelola'       => ['nullable', 'string', 'max:150'],
            'no_hp_pengelola' => ['nullable', 'string', 'max:20'],
            'email'           => ['nullable', 'email', 'max:150'],
            'kode_cabang'     => ['nullable', 'string', 'max:50'],
            'id_cabang'       => ['nullable', 'string', 'max:50'],
            'status'          => ['nullable', 'boolean'],
        ];
    }
}
