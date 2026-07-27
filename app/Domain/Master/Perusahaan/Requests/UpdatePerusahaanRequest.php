<?php

namespace App\Domain\Master\Perusahaan\Requests;

use App\Support\Enums\RoleEnum;
use App\Support\Helpers\RoleHelper;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePerusahaanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return RoleHelper::hasAnyRole($this->user(), [
            RoleEnum::ADMIN,
            RoleEnum::MANAGER,
            RoleEnum::SUPERVISOR,
        ]);
    }

    public function rules(): array
    {
        $id = $this->route('perusahaan');

        return [
            'kode_perusahaan'           => ['required', 'string', 'max:20', "unique:tb_perusahaan,kode_perusahaan,{$id}"],
            'nama_perusahaan'           => ['required', 'string', 'max:100'],
            'nama_singkatan_perusahaan' => ['nullable', 'string', 'max:20'],
            'alamat'                    => ['nullable', 'string', 'max:255'],
            'kota'                      => ['nullable', 'string', 'max:100'],
            'kode_pos'                  => ['nullable', 'string', 'max:10'],
            'no_telp'                   => ['nullable', 'string', 'max:30'],
            'email'                     => ['nullable', 'email', 'max:100'],
            'no_npwp'                   => ['nullable', 'string', 'max:30'],
            'keterangan'                => ['nullable', 'string'],
            'nama_direktur'             => ['nullable', 'string', 'max:100'],
            'segmen'                    => ['nullable', 'array'],
            'segmen.*'                  => ['string', 'in:B2B,B2C'],
            'status'                    => ['nullable', 'boolean'],
        ];
    }
}
