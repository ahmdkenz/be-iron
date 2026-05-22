<?php

namespace App\Domain\Finance\PembayaranAr\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePembayaranArRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'tanggal_pembayaran' => ['required', 'date'],
            'jumlah_pembayaran'  => ['required', 'numeric', 'min:0.01'],
            'metode_pembayaran'  => ['required', 'in:TRANSFER,CASH,GIRO'],
            'no_referensi'       => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('tb_pembayaran_ar', 'no_referensi')
                    ->whereNotNull('no_referensi'),
            ],
            'keterangan'         => ['nullable', 'string'],
            'bukti_pembayaran'   => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'no_referensi.unique' => 'Nomor referensi ini sudah digunakan pada pembayaran lain.',
        ];
    }
}
