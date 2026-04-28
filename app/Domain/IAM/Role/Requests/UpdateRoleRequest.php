<?php

namespace App\Domain\IAM\Role\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $roleId = $this->route('role');

        return [
            'name'       => ['sometimes', 'required', 'string', 'max:50', Rule::unique('tb_role', 'name')->ignore($roleId)],
            'nama_role'  => ['sometimes', 'required', 'string', 'max:100'],
            'keterangan' => ['nullable', 'string'],
            'status'     => ['nullable', 'boolean'],
        ];
    }
}
