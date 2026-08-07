<?php

namespace App\Domain\Finance\OpeningBalance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkStoreOpeningBalanceRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'items'                                 => ['required', 'array', 'min:1', 'max:50'],
            'items.*.klien_ar_id'                   => ['required', 'integer', 'exists:tb_klien_ar,id', 'distinct'],
            'items.*.tanggal'                       => ['required', 'date'],
            'items.*.saldo_awal'                    => ['required', 'numeric', 'min:0.01'],
            'items.*.keterangan'                    => ['nullable', 'string'],

            'items.*.details'                       => ['nullable', 'array', 'min:1'],
            'items.*.details.*.no_invoice_asal'     => ['required_with:items.*.details', 'string', 'max:100'],
            'items.*.details.*.tanggal_invoice_asal' => ['required_with:items.*.details', 'date'],
            'items.*.details.*.deskripsi'           => ['nullable', 'string', 'max:255'],
            'items.*.details.*.jumlah_tagihan_asal' => ['required_with:items.*.details', 'numeric', 'min:0'],
            'items.*.details.*.sisa_tagihan_asal'   => ['required_with:items.*.details', 'numeric', 'min:0.01'],
            'items.*.details.*.keterangan'          => ['nullable', 'string', 'max:500'],
            'items.*.details.*.kode_resto'          => ['nullable', 'string', 'max:50'],
            'items.*.details.*.nama_resto'          => ['nullable', 'string', 'max:100'],

            'items.*.details.*.items'               => ['nullable', 'array'],
            'items.*.details.*.items.*.barang_id'   => ['nullable', 'integer', 'exists:tb_barang,id'],
            'items.*.details.*.items.*.kode_barang' => ['nullable', 'string', 'max:50'],
            'items.*.details.*.items.*.nama_barang' => ['nullable', 'string', 'max:255'],
            'items.*.details.*.items.*.qty'         => ['nullable', 'numeric', 'min:0'],
            'items.*.details.*.items.*.satuan'      => ['nullable', 'string', 'max:20'],
            'items.*.details.*.items.*.harga_satuan' => ['nullable', 'numeric', 'min:0'],
            'items.*.details.*.items.*.subtotal'    => ['required_with:items.*.details.*.items', 'numeric', 'min:0'],
            'items.*.details.*.items.*.keterangan'  => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.klien_ar_id.distinct' => 'Client tidak boleh dipilih lebih dari sekali dalam satu pengajuan massal.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ($this->input('items', []) as $idx => $item) {
                $details = $item['details'] ?? null;
                if (empty($details)) {
                    continue;
                }

                $sum       = collect($details)->sum('sisa_tagihan_asal');
                $saldoAwal = (float) ($item['saldo_awal'] ?? 0);

                if (abs($sum - $saldoAwal) > 0.01) {
                    $validator->errors()->add(
                        "items.$idx.details",
                        'Baris ke-'.($idx + 1).': jumlah sisa tagihan pada rincian ('.number_format($sum, 2).') harus sama dengan saldo awal ('.number_format($saldoAwal, 2).')'
                    );
                }
            }
        });
    }
}
