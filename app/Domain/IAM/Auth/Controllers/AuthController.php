<?php

namespace App\Domain\IAM\Auth\Controllers;

use App\Domain\IAM\Auth\Requests\LoginRequest;
use App\Domain\IAM\User\Resources\UserResource;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    use ApiResponse;

    private const MAX_ATTEMPTS    = 5;
    private const LOCKOUT_MINUTES = 15;

    public function login(LoginRequest $request): JsonResponse
    {
        $lockKey  = 'login_lock:' . $request->username;
        $attempts = (int) Cache::get($lockKey, 0);

        if ($attempts >= self::MAX_ATTEMPTS) {
            return $this->errorResponse(
                'Akun terkunci sementara karena terlalu banyak percobaan login. Coba lagi dalam ' . self::LOCKOUT_MINUTES . ' menit.',
                429
            );
        }

        $user = User::with('roles', 'karyawan.perusahaan')
            ->where('username', $request->username)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            Cache::put($lockKey, $attempts + 1, now()->addMinutes(self::LOCKOUT_MINUTES));
            Log::channel('security')->warning('Login gagal', [
                'username'   => $request->username,
                'ip'         => $request->ip(),
                'user_agent' => $request->userAgent(),
                'attempt'    => $attempts + 1,
            ]);
            return $this->errorResponse('Username atau password salah', 401);
        }

        if (!$user->status) {
            Log::channel('security')->warning('Login ditolak - akun nonaktif', [
                'user_id'  => $user->id,
                'username' => $user->username,
                'ip'       => $request->ip(),
            ]);
            return $this->errorResponse('Akun Anda tidak aktif. Hubungi administrator.', 403);
        }

        Cache::forget($lockKey);
        Log::channel('security')->info('Login berhasil', [
            'user_id'  => $user->id,
            'username' => $user->username,
            'ip'       => $request->ip(),
        ]);

        $token        = $user->createToken('auth-token')->plainTextToken;
        $isProduction = app()->isProduction();
        $cookie       = cookie(
            'auth_token', $token, 1440, '/api',
            null,
            $isProduction,
            true,
            false,
            'Strict'
        );

        return $this->successResponse([
            'user'  => new UserResource($user),
            'token' => $token,
        ], 'Login berhasil')->withCookie($cookie);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        $isProduction = app()->isProduction();
        $expired      = cookie('auth_token', '', -1, '/api', null, $isProduction, true, false, 'Strict');

        return $this->successResponse(null, 'Logout berhasil')->withCookie($expired);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles', 'karyawan.perusahaan');
        return $this->successResponse(new UserResource($user));
    }
}
