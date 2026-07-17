<?php

namespace App\Domain\Finance\VendorAp\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVendorApRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'kode_vendor'      => ['required', 'string', 'max:20', Rule::unique('tb_vendor_ap', 'kode_vendor')],
            'nama_vendor'      => ['required', 'string', 'max:255'],
            'no_npwp'          => ['nullable', 'string', 'max:30'],
            'status_pkp'       => ['nullable', 'boolean'],
            'bank_nama'        => ['nullable', 'string', 'max:100'],
            'bank_no_rekening' => ['nullable', 'string', 'max:50'],
            'bank_atas_nama'   => ['nullable', 'string', 'max:255'],
            'karyawan_ap_id'   => ['required', 'integer', 'exists:tb_karyawan,id'],
            'status'           => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'kode_vendor' => 'Kode Supplier',
        ];
    }
}
