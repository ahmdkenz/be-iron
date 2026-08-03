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

        // Worker queue database (import master data, import rekening koran, dst).
        // --stop-when-empty membuat proses keluar sendiri begitu antrian kosong,
        // aman dipanggil ulang tiap menit oleh scheduler tanpa menumpuk daemon
        // (withoutOverlapping() jaga-jaga kalau proses sebelumnya masih berjalan
        // saat antrian sedang ramai/besar, mis. import rekening koran 1 tahun).
        $schedule->command('queue:work database --queue=bank-statement,invoice-import,default --stop-when-empty --tries=1 --timeout=1800')
            ->everyMinute()
            ->withoutOverlapping();

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
