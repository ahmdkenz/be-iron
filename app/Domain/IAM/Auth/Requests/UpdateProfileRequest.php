<?php

namespace App\Domain\IAM\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'username' => ['required', 'string', 'min:3', 'max:50', Rule::unique('tb_users', 'username')->ignore($userId)],
            'email'    => ['nullable', Rule::unique('tb_users', 'email')->ignore($userId)],
            'no_hp'    => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.required' => 'Username wajib diisi.',
            'username.min'      => 'Username minimal 3 karakter.',
            'username.max'      => 'Username maksimal 50 karakter.',
            'username.unique'   => 'Username sudah digunakan.',
            'email.unique'      => 'Email sudah digunakan.',
            'no_hp.max'         => 'No. HP maksimal 20 karakter.',
        ];
    }
}
