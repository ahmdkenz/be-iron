<?php

namespace App\Domain\Finance\Invoice\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Status progres kirim email Invoice/OB per PIC AR numpang di Laravel Cache
 * (tabel `cache` bawaan) — sengaja tanpa tabel DB baru, meniru pola persis
 * OpeningBalanceBulkApproveCacheService. TTL membuatnya otomatis pulih kalau
 * job crash tanpa sempat membersihkan penanda.
 */
class EmailBlastCacheService
{
    private const PROGRESS_TTL_MINUTES = 120;

    private const ACTIVE_LOCK_TTL_MINUTES = 35;

    public function startBatch(int $userId, int $total): string
    {
        $batchId = (string) Str::uuid();

        Cache::put($this->progressKey($batchId), [
            'batch_id'  => $batchId,
            'user_id'   => $userId,
            'status'    => 'queued',
            'total'     => $total,
            'processed' => 0,
            'sent'      => 0,
            'results'   => [],
            'message'   => null,
        ], now()->addMinutes(self::PROGRESS_TTL_MINUTES));

        Cache::put($this->activeKey($userId), $batchId, now()->addMinutes(self::ACTIVE_LOCK_TTL_MINUTES));

        return $batchId;
    }

    public function activeBatchId(int $userId): ?string
    {
        return Cache::get($this->activeKey($userId));
    }

    public function progress(string $batchId): ?array
    {
        return Cache::get($this->progressKey($batchId));
    }

    public function update(string $batchId, array $partial): void
    {
        $current = $this->progress($batchId) ?? [];

        Cache::put($this->progressKey($batchId), array_merge($current, $partial), now()->addMinutes(self::PROGRESS_TTL_MINUTES));
    }

    /** Hanya lepas lock kalau masih menunjuk batch ini (hindari race dengan batch baru). */
    public function clearActive(int $userId, string $batchId): void
    {
        if (Cache::get($this->activeKey($userId)) === $batchId) {
            Cache::forget($this->activeKey($userId));
        }
    }

    private function activeKey(int $userId): string
    {
        return "email-blast-active:{$userId}";
    }

    private function progressKey(string $batchId): string
    {
        return "email-blast-progress:{$batchId}";
    }
}
