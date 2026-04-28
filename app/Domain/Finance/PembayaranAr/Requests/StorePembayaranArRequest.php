<?php

namespace App\Domain\Finance\PembayaranAr\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePembayaranArRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'tanggal_pembayaran' => ['required', 'date'],
            'jumlah_pembayaran'  => ['required', 'numeric', 'min:0.01'],
            'metode_pembayaran'  => ['required', 'in:TRANSFER,CASH,GIRO'],
            'no_referensi'       => ['nullable', 'string', 'max:100'],
            'keterangan'         => ['nullable', 'string'],
        ];
    }
}
