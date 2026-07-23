<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    // auth_token & refresh_token sudah berupa secret ber-entropi tinggi (Sanctum
    // plainTextToken / Str::random(64)) yang dibaca ulang oleh InjectCookieToken.
    // Enkripsi tambahan di sini hanya berlaku kalau EnsureFrontendRequestsAreStateful
    // menganggap request "dari frontend" (cocok SANCTUM_STATEFUL_DOMAINS) — kalau status
    // itu berbeda antara request yang menulis cookie (login/refresh) vs yang membacanya
    // (me/refresh/dashboard), cookie ter-enkripsi di satu sisi tapi terbaca mentah di sisi
    // lain sehingga InjectCookieToken menyuntik token sampah. Dikecualikan agar auth tidak
    // bergantung pada konsistensi deteksi tsb.
    protected $except = ['auth_token', 'refresh_token'];
}
