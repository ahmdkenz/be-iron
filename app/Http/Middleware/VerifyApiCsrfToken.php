<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

class VerifyApiCsrfToken extends ValidateCsrfToken
{
    // API routes are protected by SameSite=Lax session cookies instead of
    // XSRF token headers. Cross-origin CSRF attacks are blocked because the
    // session cookie won't be sent from a different domain.
    protected $except = ['api/*'];
}
