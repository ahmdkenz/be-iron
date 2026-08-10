<?php

namespace App\Support\Helpers;

use Carbon\CarbonInterface;

/**
 * Estimasi elapsed/ETA untuk progress import async (Master Data, Invoice,
 * Opening Balance, Rekonsiliasi Bank). Rate dihitung linear dari
 * processed/elapsed sejak $startedAt.
 *
 * Sengaja mengembalikan estimated_remaining_seconds = null kalau data belum
 * cukup (elapsed di bawah ambang atau processed masih 0) — lebih baik tidak
 * menampilkan ETA daripada menampilkan tebakan ngawur di detik-detik awal
 * atau saat fase tidak punya progress per-baris (mis. auto-matching bank
 * statement).
 */
class ImportEtaCalculator
{
    private const MIN_ELAPSED_SECONDS = 2;

    /**
     * @return array{elapsed_seconds: ?int, estimated_remaining_seconds: ?int, estimated_completion_at: ?string}
     */
    public static function compute(?CarbonInterface $startedAt, int $processed, int $total): array
    {
        if (!$startedAt) {
            return [
                'elapsed_seconds' => null,
                'estimated_remaining_seconds' => null,
                'estimated_completion_at' => null,
            ];
        }

        $elapsed = max(0, $startedAt->diffInSeconds(now()));

        if ($processed <= 0 || $total <= 0 || $elapsed < self::MIN_ELAPSED_SECONDS) {
            return [
                'elapsed_seconds' => $elapsed,
                'estimated_remaining_seconds' => null,
                'estimated_completion_at' => null,
            ];
        }

        $remaining = max(0, $total - $processed);
        if ($remaining === 0) {
            return [
                'elapsed_seconds' => $elapsed,
                'estimated_remaining_seconds' => 0,
                'estimated_completion_at' => now()->toIso8601String(),
            ];
        }

        $rate = $processed / $elapsed;
        $etaSeconds = (int) round($remaining / $rate);

        return [
            'elapsed_seconds' => $elapsed,
            'estimated_remaining_seconds' => $etaSeconds,
            'estimated_completion_at' => now()->addSeconds($etaSeconds)->toIso8601String(),
        ];
    }
}
