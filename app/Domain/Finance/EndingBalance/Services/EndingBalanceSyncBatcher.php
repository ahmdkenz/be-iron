<?php

namespace App\Domain\Finance\EndingBalance\Services;

use Illuminate\Support\Facades\Log;

/**
 * Dedup layer di atas EndingBalanceService::syncEbForKlien() untuk operasi
 * bulk (import) yang menyentuh banyak Invoice sekaligus untuk klien+periode
 * yang sama berulang kali.
 *
 * TIDAK mengubah kapan InvoiceObserver::syncEb() "boleh" jalan — hanya
 * mengganti apa yang terjadi di dalam callback DB::afterCommit() yang sudah
 * ada, sehingga timing/rollback-safety bawaan Laravel tetap dipakai apa
 * adanya.
 *
 * State statis di kelas ini hanya aman untuk SATU eksekusi Job (satu proses
 * PHP, satu alur kontrol tunggal dari awal sampai akhir) — bukan untuk
 * berbagi state lintas Job. Setiap job yang butuh batching membuka dan
 * menutup batch-nya sendiri lewat run().
 */
class EndingBalanceSyncBatcher
{
    private static int $depth = 0;

    /** @var array<string, array{klien_ar_id:int, periode_awal:string, periode_akhir:string, user_id:int}> */
    private static array $pending = [];

    public static function isActive(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Jalankan $callback dengan mode batching aktif. Aman untuk nesting
     * (hanya level terluar yang benar-benar flush) dan aman terhadap
     * exception di tengah jalan (finally menjamin depth turun & flush tetap
     * terjadi untuk key yang sudah sempat ter-collect sebelum exception).
     */
    public static function run(callable $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;

            if (self::$depth === 0) {
                self::flush();
            }
        }
    }

    public static function collect(int $klienId, string $periodeAwal, string $periodeAkhir, int $userId): void
    {
        $key = $klienId . '|' . $periodeAwal . '|' . $periodeAkhir;

        self::$pending[$key] ??= [
            'klien_ar_id'   => $klienId,
            'periode_awal'  => $periodeAwal,
            'periode_akhir' => $periodeAkhir,
            'user_id'       => $userId,
        ];
    }

    private static function flush(): void
    {
        if (empty(self::$pending)) {
            return;
        }

        $batch = self::$pending;
        self::$pending = [];

        $ebService = app(EndingBalanceService::class);

        foreach ($batch as $item) {
            try {
                $ebService->syncEbForKlien(
                    $item['klien_ar_id'],
                    $item['periode_awal'],
                    $item['periode_akhir'],
                    $item['user_id'],
                );
            } catch (\Throwable $e) {
                Log::error('EndingBalanceSyncBatcher: gagal sync EB', [
                    'klien_ar_id'   => $item['klien_ar_id'],
                    'periode_awal'  => $item['periode_awal'],
                    'periode_akhir' => $item['periode_akhir'],
                    'error'         => $e->getMessage(),
                ]);
            }
        }
    }

    /** Hanya dipakai test — paksa reset state statis di antara test case. */
    public static function resetForTesting(): void
    {
        self::$depth = 0;
        self::$pending = [];
    }
}
