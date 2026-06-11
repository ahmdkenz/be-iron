<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InjectCookieToken
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->bearerToken() && $request->hasCookie('auth_token')) {
            $request->headers->set('Authorization', 'Bearer ' . $request->cookie('auth_token'));
        }

        return $next($request);
    }
}
