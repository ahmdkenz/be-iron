<?php

namespace App\Domain\IAM\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;

class ForgotPasswordController extends Controller
{
    use ApiResponse;

    public function sendLink(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
        ], [
            'email.required' => 'Email wajib diisi.',
            'email.email'    => 'Format email tidak valid.',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validasi gagal.', 422, $validator->errors()->toArray());
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            // Jangan bocorkan apakah email terdaftar atau tidak
            return $this->successResponse(null, 'Jika email terdaftar, link reset password telah dikirim.');
        }

        if (is_null($user->email_verified_at)) {
            return $this->errorResponse(
                'Email belum diverifikasi. Silakan verifikasi email Anda terlebih dahulu sebelum melakukan reset password.',
                422
            );
        }

        Password::sendResetLink(['email' => $request->email]);

        return $this->successResponse(null, 'Jika email terdaftar, link reset password telah dikirim.');
    }

    public function reset(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token'                 => ['required', 'string'],
            'email'                 => ['required', 'email'],
            'password'              => ['required', 'string', 'min:8', 'confirmed', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).+$/'],
            'password_confirmation' => ['required', 'string'],
        ], [
            'token.required'                 => 'Token reset tidak valid.',
            'email.required'                 => 'Email wajib diisi.',
            'email.email'                    => 'Format email tidak valid.',
            'password.required'              => 'Password baru wajib diisi.',
            'password.min'                   => 'Password minimal 8 karakter.',
            'password.confirmed'             => 'Konfirmasi password tidak cocok.',
            'password.regex'                 => 'Password harus mengandung huruf besar, huruf kecil, angka, dan karakter spesial.',
            'password_confirmation.required' => 'Konfirmasi password wajib diisi.',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validasi gagal.', 422, $validator->errors()->toArray());
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                // Revoke semua token Sanctum agar user harus login ulang
                $user->tokens()->delete();
                $user->update(['refresh_token' => null]);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return $this->errorResponse(
                $status === Password::INVALID_TOKEN
                    ? 'Token reset tidak valid atau telah kedaluwarsa.'
                    : 'Gagal mereset password. Silakan coba lagi.',
                422
            );
        }

        return $this->successResponse(null, 'Password berhasil direset. Silakan login dengan password baru.');
    }
}
