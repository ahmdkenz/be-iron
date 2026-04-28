<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateByQueryToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->query('token');

        if (! $token) {
            abort(401, 'Token tidak ditemukan.');
        }

        $pat = PersonalAccessToken::findToken($token);

        if (! $pat || ! $pat->tokenable) {
            abort(401, 'Token tidak valid atau sudah kadaluarsa.');
        }

        auth()->login($pat->tokenable);

        return $next($request);
    }
}
