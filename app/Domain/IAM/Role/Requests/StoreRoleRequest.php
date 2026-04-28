<?php

namespace App\Domain\IAM\Role\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'       => ['required', 'string', 'max:50', 'unique:tb_role,name'],
            'nama_role'  => ['required', 'string', 'max:100'],
            'keterangan' => ['nullable', 'string'],
            'status'     => ['nullable', 'boolean'],
        ];
    }
}
