<?php

namespace App\Domain\Finance\VendorAp\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVendorApRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'nama_vendor'      => ['required', 'string', 'max:255'],
            'no_npwp'          => ['nullable', 'string', 'max:30'],
            'status_pkp'       => ['nullable', 'boolean'],
            'kategori'         => ['nullable', 'string', 'max:100'],
            'termin_hari'      => ['nullable', 'integer', 'min:0'],
            'bank_nama'        => ['nullable', 'string', 'max:100'],
            'bank_no_rekening' => ['nullable', 'string', 'max:50'],
            'bank_atas_nama'   => ['nullable', 'string', 'max:255'],
            'perusahaan_id'    => ['required', 'integer', 'exists:tb_perusahaan,id'],
            'karyawan_ap_id'   => ['required', 'integer', 'exists:tb_karyawan,id'],
            'status'           => ['nullable', 'boolean'],
        ];
    }
}
