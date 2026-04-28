<?php

namespace App\Domain\Finance\OpeningBalance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOpeningBalanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'klien_ar_id'  => ['required', 'integer', 'exists:tb_klien_ar,id'],
            'tanggal'      => ['required', 'date'],
            'periode_awal' => ['required', 'date'],
            'periode_akhir'=> ['required', 'date', 'after_or_equal:periode_awal'],
            'saldo_awal'   => ['required', 'numeric', 'min:0.01'],
            'keterangan'   => ['nullable', 'string'],
        ];
    }
}
