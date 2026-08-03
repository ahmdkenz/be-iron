<?php

namespace App\Domain\IAM\User\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'username'    => ['required', 'string', 'min:3', 'max:50', 'unique:tb_users,username'],
            'email'       => ['nullable', 'unique:tb_users,email'],
            'password'    => ['required', 'string', 'min:8'],
            'karyawan_id' => ['nullable', 'integer', 'exists:tb_karyawan,id'],
            'role_id'     => ['required', 'integer', 'exists:tb_role,id'],
            'no_hp'       => ['nullable', 'string', 'max:20'],
            'status'      => ['nullable', 'boolean'],
            'fonnte_token' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.min'   => 'Password minimal 8 karakter.',
        ];
    }
}
