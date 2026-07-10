<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

class VerifyApiCsrfToken extends ValidateCsrfToken
{
    // Middleware ini hanya berjalan untuk request yang dikenali sebagai SPA
    // frontend (Origin/Referer cocok SANCTUM_STATEFUL_DOMAINS) lewat
    // EnsureFrontendRequestsAreStateful — lihat bootstrap/app.php. Bearer-token
    // API client non-browser tidak pernah masuk pipeline ini sehingga tidak
    // terdampak CSRF (mereka tidak bergantung pada cookie).
    protected $except = [];
}
