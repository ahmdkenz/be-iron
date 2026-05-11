<?php

namespace App\Domain\Finance\OpeningBalance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOpeningBalanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'klien_ar_id'                    => ['required', 'integer', 'exists:tb_klien_ar,id'],
            'tanggal'                        => ['required', 'date'],
            'periode_awal'                   => ['required', 'date'],
            'periode_akhir'                  => ['required', 'date', 'after_or_equal:periode_awal'],
            'saldo_awal'                     => ['required', 'numeric', 'min:0.01'],
            'keterangan'                     => ['nullable', 'string'],

            'details'                               => ['nullable', 'array', 'min:1'],
            'details.*.no_invoice_asal'             => ['required_with:details', 'string', 'max:100'],
            'details.*.tanggal_invoice_asal'        => ['required_with:details', 'date'],
            'details.*.deskripsi'                   => ['required_with:details', 'string', 'max:255'],
            'details.*.jumlah_tagihan_asal'         => ['required_with:details', 'numeric', 'min:0'],
            'details.*.sisa_tagihan_asal'           => ['required_with:details', 'numeric', 'min:0.01'],
            'details.*.keterangan'                  => ['nullable', 'string', 'max:500'],

            'details.*.items'                       => ['nullable', 'array'],
            'details.*.items.*.barang_id'           => ['nullable', 'integer', 'exists:tb_barang,id'],
            'details.*.items.*.nama_barang'         => ['required_with:details.*.items', 'string', 'max:255'],
            'details.*.items.*.qty'                 => ['required_with:details.*.items', 'numeric', 'min:0.001'],
            'details.*.items.*.satuan'              => ['nullable', 'string', 'max:20'],
            'details.*.items.*.harga_satuan'        => ['required_with:details.*.items', 'numeric', 'min:0'],
            'details.*.items.*.subtotal'            => ['required_with:details.*.items', 'numeric', 'min:0'],
            'details.*.items.*.keterangan'          => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $details = $this->input('details');
            if (empty($details)) {
                return;
            }

            $sum       = collect($details)->sum('sisa_tagihan_asal');
            $saldoAwal = (float) $this->input('saldo_awal');

            if (abs($sum - $saldoAwal) > 0.01) {
                $validator->errors()->add(
                    'details',
                    'Jumlah sisa tagihan pada rincian (' . number_format($sum, 2) . ') harus sama dengan saldo awal (' . number_format($saldoAwal, 2) . ')'
                );
            }
        });
    }
}
