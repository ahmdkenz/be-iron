<?php

namespace App\Domain\Finance\EndingBalance\Services;

use App\Models\EndingBalance;
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

    /**
     * Dedup untuk InvoiceService::recalculateDraftEndingBalance() — beda dari $pending
     * (yang mem-flush syncEbForKlien(), termasuk cascade forward). recalculate() cuma
     * menghitung ulang 1 EndingBalance DRAFT yang SUDAH ADA tanpa cascade, jadi dedup-nya
     * langsung per ending_balance_id (bukan per klien+periode).
     *
     * @var array<int, int> ending_balance_id => user_id
     */
    private static array $pendingRecalculate = [];

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

    /**
     * Dedup untuk InvoiceService::recalculateDraftEndingBalance() (dipanggil per invoice
     * SAFE_UPDATE dari InvoiceGroupProcessor::updateInvoice()). Beberapa invoice yang
     * jatuh ke EndingBalance DRAFT yang sama dalam 1 chunk import cukup di-recalculate()
     * sekali di akhir, bukan berulang per invoice.
     */
    public static function collectRecalculate(int $endingBalanceId, int $userId): void
    {
        self::$pendingRecalculate[$endingBalanceId] = $userId;
    }

    private static function flush(): void
    {
        if (empty(self::$pending) && empty(self::$pendingRecalculate)) {
            return;
        }

        $batch = self::$pending;
        self::$pending = [];
        $recalculateBatch = self::$pendingRecalculate;
        self::$pendingRecalculate = [];

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

        foreach ($recalculateBatch as $endingBalanceId => $userId) {
            try {
                $eb = EndingBalance::find($endingBalanceId);
                if ($eb) {
                    $ebService->recalculate($eb, $userId);
                }
            } catch (\Throwable $e) {
                Log::error('EndingBalanceSyncBatcher: gagal recalculate EB', [
                    'ending_balance_id' => $endingBalanceId,
                    'error'             => $e->getMessage(),
                ]);
            }
        }
    }

    /** Hanya dipakai test — paksa reset state statis di antara test case. */
    public static function resetForTesting(): void
    {
        self::$depth = 0;
        self::$pending = [];
        self::$pendingRecalculate = [];
    }
}
