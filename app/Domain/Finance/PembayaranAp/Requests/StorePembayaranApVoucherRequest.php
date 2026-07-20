<?php

namespace App\Domain\Finance\PembayaranAp\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePembayaranApVoucherRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'tanggal_pembayaran'        => ['required', 'date'],
            'metode_pembayaran'         => ['required', 'in:TRANSFER,CASH,GIRO'],
            'kategori_voucher'          => ['required', 'in:BB,NBB'],
            'no_referensi'              => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('tb_pembayaran_ap', 'no_referensi')
                    ->whereNotNull('no_referensi'),
            ],
            'keterangan'                => ['nullable', 'string'],
            'bukti_pembayaran'          => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'alokasi'                   => ['required', 'array', 'min:1'],
            'alokasi.*.tagihan_ap_id'   => ['required', 'integer', 'exists:tb_tagihan_ap,id'],
            'alokasi.*.jumlah'          => ['required', 'numeric', 'min:0.01'],
        ];
    }

    public function messages(): array
    {
        return [
            'no_referensi.unique'            => 'Nomor referensi ini sudah digunakan pada pembayaran lain.',
            'kategori_voucher.required'       => 'Kategori voucher wajib dipilih.',
            'kategori_voucher.in'             => 'Kategori voucher harus Bahan Baku atau Non Bahan Baku.',
            'alokasi.required'                => 'Voucher harus mencakup minimal 1 tagihan.',
            'alokasi.min'                      => 'Voucher harus mencakup minimal 1 tagihan.',
            'alokasi.*.tagihan_ap_id.required' => 'Tagihan wajib dipilih untuk setiap baris alokasi.',
            'alokasi.*.jumlah.required'        => 'Jumlah pembayaran wajib diisi untuk setiap baris alokasi.',
            'alokasi.*.jumlah.min'             => 'Jumlah pembayaran harus lebih dari 0.',
        ];
    }
}
