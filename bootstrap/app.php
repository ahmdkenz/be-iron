<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Route /broadcasting/auth lewat middleware 'api' (EnsureFrontendRequestsAreStateful +
    // InjectCookieToken ikut prepend otomatis, lihat withMiddleware() di bawah) + auth:sanctum,
    // BUKAN default group 'web' bawaan Broadcast::routes(). Auth aplikasi ini pakai bearer
    // token di cookie httpOnly 'auth_token' (lihat InjectCookieToken), bukan session guard
    // 'web', jadi channel authorization wajib melalui middleware yang sama dengan routes/api.php.
    // Prefix disamakan (api/v1) supaya masuk pola CORS paths 'api/*' yang sudah ada tanpa
    // perlu menambah config/cors.php.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        [
            'prefix' => 'api/v1',
            'middleware' => ['api', 'throttle:60,1', 'auth:sanctum'],
        ],
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Tarik PO & Terima PO dari SHZ360 untuk staging AP. Butuh crontab asli
        // di hosting (`* * * * * php artisan schedule:run`) — ini penggunaan
        // scheduler pertama di project ini, belum ada infra cron sebelumnya.
        $schedule->command('ap:sync-shz360-po')->everyFiveMinutes()->withoutOverlapping();

        // 2 worker queue database TERPISAH (2026-08-07, sebelumnya 1 worker mencakup semua
        // queue dalam 1 urutan prioritas) — supaya import rekening koran (bank-statement,
        // bisa berjalan lama untuk 1 tahun data) tidak men-starve import master
        // data/invoice/opening balance yang antre di belakangnya, dan sebaliknya. Masing-
        // masing --stop-when-empty supaya proses keluar sendiri begitu antriannya kosong,
        // aman dipanggil ulang tiap menit oleh scheduler tanpa menumpuk daemon
        // (withoutOverlapping() jaga-jaga kalau proses sebelumnya masih berjalan saat
        // antrian sedang ramai/besar). withoutOverlapping() per baris HANYA mengunci
        // instance dirinya sendiri (nama command berbeda), jadi kedua worker ini tetap
        // bisa berjalan BERSAMAAN sebagai 2 proses OS terpisah.
        // runInBackground() WAJIB — tanpa ini, Schedule::run() menjalankan kedua command
        // queue:work di bawah SECARA BERURUTAN dalam 1 proses schedule:run (bukan paralel
        // seperti komentar di atas mengklaim): kalau bank-statement sedang sibuk (mis. import
        // Rekening Koran 1 tahun data), invoice-import (tempat Import Master Invoice/Opening
        // Balance antre) baru mulai setelah command pertama keluar dalam tick yang sama.
        $schedule->command('queue:work database --queue=bank-statement --stop-when-empty --tries=1 --timeout=1800')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // Import master data, import master invoice, import opening balance, cetak PDF.
        $schedule->command('queue:work database --queue=invoice-import,invoice-print,default --stop-when-empty --tries=1 --timeout=1800')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // Menggantikan pemanggilan failStale()/cancelAbandonedConfirmations() yang
        // sebelumnya inline di BankStatementController: dipanggil di setiap request
        // polling importStatus() (tiap 2.5 detik dari FE), query UPDATE-nya sempat
        // mengunci baris batch yang sedang aktif ditulis oleh transaksi finalize()
        // milik queue worker → deadlock SQLSTATE[40001] → 500 di polling FE.
        $schedule->command('bank-statement:cleanup-stale-imports')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        // Baris tb_bulk_print_tokens (link publik "Kirim Bulk Investor") tidak
        // ada gunanya lagi setelah signed URL-nya sendiri kedaluwarsa (30 hari).
        $schedule->command('bulk-print-token:cleanup')
            ->daily()
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // App hanya reachable lewat reverse proxy (tidak ada jalur langsung ke PHP-FPM
        // dari internet), jadi at: '*' aman — tidak ada klien luar yang bisa memalsukan
        // X-Forwarded-For langsung ke app. Dibutuhkan supaya $request->ip()/->secure()
        // di AuthController (lockout key, cookie secure flag) dan SecurityHeaders (HSTS
        // gate) membaca data request asli, bukan data reverse proxy.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \App\Http\Middleware\InjectCookieToken::class,
        ]);

        $middleware->alias([
            'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
