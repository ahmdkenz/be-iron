<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateByQueryToken
{
    private const MAX_ATTEMPTS    = 10;
    private const DECAY_SECONDS   = 60;
    private const TOKEN_MAX_AGE_MINUTES = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $ip      = $request->ip();
        $lockKey = 'qtoken_attempt:' . $ip;

        if (Cache::get($lockKey, 0) >= self::MAX_ATTEMPTS) {
            abort(429, 'Terlalu banyak percobaan. Coba lagi nanti.');
        }

        $token = $request->query('token');

        if (! $token) {
            abort(401, 'Token tidak ditemukan.');
        }

        $pat = PersonalAccessToken::findToken($token);

        if (! $pat || ! $pat->tokenable) {
            Cache::put($lockKey, Cache::get($lockKey, 0) + 1, self::DECAY_SECONDS);
            abort(401, 'Token tidak valid atau sudah kadaluarsa.');
        }

        if ($pat->created_at->diffInMinutes(now()) > self::TOKEN_MAX_AGE_MINUTES) {
            abort(401, 'Token sudah kadaluarsa.');
        }

        Cache::forget($lockKey);
        Auth::setUser($pat->tokenable);

        return $next($request);
    }
}
