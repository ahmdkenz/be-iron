<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Tarik PO & Terima PO dari SHZ360 untuk staging AP. Butuh crontab asli
        // di hosting (`* * * * * php artisan schedule:run`) — ini penggunaan
        // scheduler pertama di project ini, belum ada infra cron sebelumnya.
        $schedule->command('ap:sync-shz360-po')->everyFiveMinutes()->withoutOverlapping();

        // Worker queue database (import master data, import rekening koran, dst).
        // --stop-when-empty membuat proses keluar sendiri begitu antrian kosong,
        // aman dipanggil ulang tiap menit oleh scheduler tanpa menumpuk daemon
        // (withoutOverlapping() jaga-jaga kalau proses sebelumnya masih berjalan
        // saat antrian sedang ramai/besar, mis. import rekening koran 1 tahun).
        $schedule->command('queue:work database --queue=bank-statement,default --stop-when-empty --tries=1 --timeout=1800')
            ->everyMinute()
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \App\Http\Middleware\InjectCookieToken::class,
        ]);

        $middleware->alias([
            'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'auth.query_token'   => \App\Http\Middleware\AuthenticateByQueryToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
