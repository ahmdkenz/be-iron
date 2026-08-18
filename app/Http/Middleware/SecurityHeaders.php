<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        // max-age 1 minggu (604800) untuk staged rollout — naikkan ke 31536000 (1 tahun)
        // setelah dipastikan aman di production beberapa minggu. HSTS bersifat "sticky" di
        // browser sekali diterima, jadi mulai pendek dulu supaya bisa dibatalkan kalau perlu.
        if ($request->secure() && app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=604800; includeSubDomains');
        }

        // style-src butuh 'unsafe-inline' karena ada inline <style> di beberapa Blade view
        // (welcome.blade.php, verify-document.blade.php, invoice-print.blade.php). Tidak ada
        // inline <script> di be-iron sehingga script-src 'self' tanpa unsafe-inline aman.
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' https://fonts.bunny.net data:",
            "img-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]));

        return $response;
    }
}
