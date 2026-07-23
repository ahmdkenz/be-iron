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
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use ApiResponse;

    private const MAX_ATTEMPTS      = 5;
    private const LOCKOUT_MINUTES   = 15;
    private const REFRESH_TOKEN_TTL = 60 * 24 * 30; // 30 hari dalam menit

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

        $accessToken  = $user->createToken('auth-token')->plainTextToken;
        $refreshToken = Str::random(64);
        $user->update(['refresh_token' => hash('sha256', $refreshToken)]);

        $isProduction   = app()->isProduction();
        $accessCookie   = cookie('auth_token', $accessToken, 1440, '/api', null, $isProduction, true, false, 'Lax');
        $refreshCookie  = cookie('refresh_token', $refreshToken, self::REFRESH_TOKEN_TTL, '/api/v1/auth', null, $isProduction, true, false, 'Lax');

        return $this->successResponse([
            'user' => new UserResource($user),
        ], 'Login berhasil')->withCookie($accessCookie)->withCookie($refreshCookie);
    }

    public function refresh(Request $request): JsonResponse
    {
        $refreshToken = $request->cookie('refresh_token');

        if (!$refreshToken) {
            return $this->errorResponse('Refresh token tidak ditemukan.', 401);
        }

        $user = User::where('refresh_token', hash('sha256', $refreshToken))->first();

        if (!$user || !$user->status) {
            return $this->errorResponse('Refresh token tidak valid atau telah kedaluwarsa.', 401);
        }

        $user->tokens()->delete();

        $newAccessToken  = $user->createToken('auth-token')->plainTextToken;
        $newRefreshToken = Str::random(64);
        $user->update(['refresh_token' => hash('sha256', $newRefreshToken)]);

        $isProduction  = app()->isProduction();
        $accessCookie  = cookie('auth_token', $newAccessToken, 1440, '/api', null, $isProduction, true, false, 'Lax');
        $refreshCookie = cookie('refresh_token', $newRefreshToken, self::REFRESH_TOKEN_TTL, '/api/v1/auth', null, $isProduction, true, false, 'Lax');

        return $this->successResponse(null, 'Token diperbarui')->withCookie($accessCookie)->withCookie($refreshCookie);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['refresh_token' => null]);
        $user->currentAccessToken()->delete();

        $isProduction  = app()->isProduction();
        $expiredAccess  = cookie('auth_token', '', -1, '/api', null, $isProduction, true, false, 'Lax');
        $expiredRefresh = cookie('refresh_token', '', -1, '/api/v1/auth', null, $isProduction, true, false, 'Lax');

        return $this->successResponse(null, 'Logout berhasil')->withCookie($expiredAccess)->withCookie($expiredRefresh);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles', 'karyawan.perusahaan');
        return $this->successResponse(new UserResource($user));
    }

    // Endpoint publik: selalu 200, tidak pernah 401. Dipakai router guard FE untuk
    // cek "apakah browser ini masih punya sesi valid" TANPA localStorage marker —
    // baca langsung cookie HttpOnly auth_token/refresh_token di server.
    // InjectCookieToken (middleware global grup 'api') sudah menyalin cookie auth_token
    // jadi header Authorization sebelum controller ini jalan, jadi $request->user('sanctum')
    // otomatis memvalidasi expiry lewat Sanctum Guard seperti endpoint /me.
    public function session(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');

        if ($user) {
            return $this->successResponse([
                'authenticated' => true,
                'user'          => new UserResource($user->load('roles', 'karyawan.perusahaan')),
            ]);
        }

        $refreshToken = $request->cookie('refresh_token');
        $hasValidRefreshToken = $refreshToken
            && User::where('refresh_token', hash('sha256', $refreshToken))->where('status', true)->exists();

        if ($hasValidRefreshToken) {
            return $this->successResponse([
                'authenticated'    => true,
                'refresh_required' => true,
            ]);
        }

        return $this->successResponse([
            'authenticated' => false,
        ]);
    }
}
