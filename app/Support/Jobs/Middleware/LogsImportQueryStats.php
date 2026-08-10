<?php

namespace App\Support\Jobs\Middleware;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job middleware ringan: log jumlah query + waktu query + wall time per eksekusi job.
 * Dipasang lewat method middleware() pada job import (Master Data/Invoice/Opening
 * Balance) untuk pertama kalinya memberi angka NYATA dari production — menutup gap
 * yang sebelumnya hanya diverifikasi lewat unit test lokal (lihat memory
 * project_import_speed_optimization_round2 & project_invoice_import_speed_optimization:
 * "verifikasi manual staging DB nyata... belum dilakukan" dicatat 2x sebagai gap).
 *
 * DB::listen() tidak punya API "unlisten" resmi — daripada listener menumpuk tiap job
 * baru (1 proses queue:work bisa memproses banyak job berurutan sebelum --stop-when-empty
 * keluar), listener didaftarkan SEKALI per proses (guard $listenerRegistered statis) yang
 * hanya menambah counter statis; tiap job baca delta counter sebelum/sesudah, bukan
 * berasumsi mulai dari nol.
 */
class LogsImportQueryStats
{
    private static bool $listenerRegistered = false;

    private static int $queryCount = 0;

    private static float $queryTimeMs = 0.0;

    public function handle(mixed $job, callable $next): mixed
    {
        $this->registerListenerOnce();

        $countBefore = self::$queryCount;
        $timeBefore = self::$queryTimeMs;
        $startedAt = microtime(true);

        try {
            return $next($job);
        } finally {
            Log::info('Import job performance', [
                'job' => get_class($job),
                'wall_ms' => round((microtime(true) - $startedAt) * 1000, 1),
                'query_count' => self::$queryCount - $countBefore,
                'query_time_ms' => round(self::$queryTimeMs - $timeBefore, 1),
            ]);
        }
    }

    private function registerListenerOnce(): void
    {
        if (self::$listenerRegistered) {
            return;
        }

        DB::listen(function ($query): void {
            self::$queryCount++;
            self::$queryTimeMs += $query->time;
        });

        self::$listenerRegistered = true;
    }
}
