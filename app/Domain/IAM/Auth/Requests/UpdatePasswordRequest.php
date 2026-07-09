<?php

namespace App\Domain\IAM\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'current_password'          => ['required', 'string'],
            'new_password'              => ['required', 'string', 'min:8', 'confirmed'],
            'new_password_confirmation' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required'          => 'Password saat ini wajib diisi.',
            'new_password.required'              => 'Password baru wajib diisi.',
            'new_password.min'                   => 'Password baru minimal 8 karakter.',
            'new_password.confirmed'             => 'Konfirmasi password tidak cocok.',
            'new_password_confirmation.required' => 'Konfirmasi password wajib diisi.',
        ];
    }
}
