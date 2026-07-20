<?php

namespace App\Domain\Finance\PembayaranAp\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePembayaranApRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'tanggal_pembayaran' => ['required', 'date'],
            'jumlah_pembayaran'  => ['required', 'numeric', 'min:0.01'],
            'metode_pembayaran'  => ['required', 'in:TRANSFER,CASH,GIRO'],
            'kategori_voucher'   => ['required', 'in:BB,NBB'],
            'keterangan'         => ['nullable', 'string'],
            'bukti_pembayaran'   => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'kategori_voucher.required' => 'Kategori voucher wajib dipilih.',
            'kategori_voucher.in'       => 'Kategori voucher harus Bahan Baku atau Non Bahan Baku.',
        ];
    }
}
