<?php

namespace App\Domain\IAM\User\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username'    => ['required', 'string', 'min:3', 'max:50', 'unique:tb_users,username'],
            'email'       => ['required', 'email', 'unique:tb_users,email'],
            'password'    => ['required', 'string', 'min:6'],
            'karyawan_id' => ['nullable', 'integer', 'exists:tb_karyawan,id'],
            'role_id'     => ['required', 'integer', 'exists:tb_role,id'],
            'no_hp'       => ['nullable', 'string', 'max:20'],
            'status'      => ['nullable', 'boolean'],
        ];
    }
}
