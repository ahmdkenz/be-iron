<?php

namespace App\Domain\IAM\User\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $userId = $this->route('user');

        return [
            'username'    => ['sometimes', 'required', 'string', 'min:3', 'max:50', Rule::unique('tb_users', 'username')->ignore($userId)],
            'email'       => ['sometimes', 'nullable', Rule::unique('tb_users', 'email')->ignore($userId)],
            'password'    => ['nullable', 'string', 'min:8'],
            'karyawan_id' => ['nullable', 'integer', 'exists:tb_karyawan,id'],
            'role_id'     => ['sometimes', 'required', 'integer', 'exists:tb_role,id'],
            'no_hp'       => ['nullable', 'string', 'max:20'],
            'status'      => ['nullable', 'boolean'],
            'smtp_host'       => ['nullable', 'string', 'max:255'],
            'smtp_port'       => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_username'   => ['nullable', 'string', 'max:255'],
            'smtp_password'   => ['nullable', 'string', 'max:255'],
            'smtp_encryption' => ['nullable', 'in:tls,ssl'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.min'   => 'Password minimal 8 karakter.',
        ];
    }
}
