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

    private const MAX_ATTEMPTS            = 5;
    private const LOCKOUT_MINUTES         = 15;
    private const REFRESH_TOKEN_TTL       = 60 * 24 * 30; // 30 hari dalam menit — dipakai saat remember=true
    private const REFRESH_TOKEN_TTL_SHORT = 60; // 1 jam dalam menit — backstop server-side saat remember=false

    // Refresh token dibungkus payload bertanda-tangan (bukan kolom DB baru) supaya expiry
    // & pilihan "remember" bisa ditegakkan di server tanpa migration. Signature pakai APP_KEY
    // sehingga payload (exp, rem) tidak bisa dipalsukan dari sisi client.
    private function buildRefreshToken(bool $remember): string
    {
        $ttlMinutes = $remember ? self::REFRESH_TOKEN_TTL : self::REFRESH_TOKEN_TTL_SHORT;

        $payload = base64_encode(json_encode([
            'r'   => Str::random(40),
            'exp' => now()->addMinutes($ttlMinutes)->timestamp,
            'rem' => $remember,
        ]));

        $signature = hash_hmac('sha256', $payload, config('app.key'));

        return $payload . '.' . $signature;
    }

    private function parseRefreshToken(?string $token): ?array
    {
        if (!$token || !str_contains($token, '.')) {
            return null;
        }

        [$payload, $signature] = explode('.', $token, 2);
        $expectedSignature = hash_hmac('sha256', $payload, config('app.key'));

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $data = json_decode(base64_decode($payload), true);

        if (!is_array($data) || !isset($data['exp'], $data['rem']) || $data['exp'] < now()->timestamp) {
            return null;
        }

        return $data;
    }

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

        $remember = $request->boolean('remember');

        $accessToken  = $user->createToken('auth-token')->plainTextToken;
        $refreshToken = $this->buildRefreshToken($remember);
        $user->update(['refresh_token' => hash('sha256', $refreshToken)]);

        // remember=false: cookie session-only (hilang saat browser ditutup), dibatasi juga
        // oleh 'exp' di dalam refresh token (REFRESH_TOKEN_TTL_SHORT) sebagai backstop server.
        $isProduction   = app()->isProduction();
        $accessMinutes  = $remember ? 1440 : 0;
        $refreshMinutes = $remember ? self::REFRESH_TOKEN_TTL : 0;
        $accessCookie   = cookie('auth_token', $accessToken, $accessMinutes, '/api', null, $isProduction, true, false, 'Lax');
        $refreshCookie  = cookie('refresh_token', $refreshToken, $refreshMinutes, '/api/v1/auth', null, $isProduction, true, false, 'Lax');

        return $this->successResponse([
            'user' => new UserResource($user),
        ], 'Login berhasil')->withCookie($accessCookie)->withCookie($refreshCookie);
    }

    public function refresh(Request $request): JsonResponse
    {
        $refreshToken = $request->cookie('refresh_token');
        $payload      = $this->parseRefreshToken($refreshToken);

        if (!$payload) {
            return $this->errorResponse('Refresh token tidak ditemukan.', 401);
        }

        $user = User::where('refresh_token', hash('sha256', $refreshToken))->first();

        if (!$user || !$user->status) {
            return $this->errorResponse('Refresh token tidak valid atau telah kedaluwarsa.', 401);
        }

        $user->tokens()->delete();

        $remember = (bool) $payload['rem'];

        $newAccessToken  = $user->createToken('auth-token')->plainTextToken;
        $newRefreshToken = $this->buildRefreshToken($remember);
        $user->update(['refresh_token' => hash('sha256', $newRefreshToken)]);

        $isProduction   = app()->isProduction();
        $accessMinutes  = $remember ? 1440 : 0;
        $refreshMinutes = $remember ? self::REFRESH_TOKEN_TTL : 0;
        $accessCookie   = cookie('auth_token', $newAccessToken, $accessMinutes, '/api', null, $isProduction, true, false, 'Lax');
        $refreshCookie  = cookie('refresh_token', $newRefreshToken, $refreshMinutes, '/api/v1/auth', null, $isProduction, true, false, 'Lax');

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
        $hasValidRefreshToken = $this->parseRefreshToken($refreshToken)
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
