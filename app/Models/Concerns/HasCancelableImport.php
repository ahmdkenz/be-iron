<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Cache;

/**
 * Flag "batal diminta" (dan, untuk batch yang belum punya kolom `phase` asli, penanda fase
 * saat ini) disimpan di Cache — BUKAN di kolom `errors` json milik batch. `errors` dipakai
 * Invoice & Master Data sebagai daftar flat {sheet,row,message} yang di-count() untuk pesan
 * "X error saat parsing"; menumpuk key lain di situ akan merusak hitungan itu. Pola Cache ini
 * meniru OpeningBalanceBulkApproveCacheService yang sudah ada untuk kebutuhan serupa (state
 * sementara seputar import yang sengaja tidak butuh migration baru).
 *
 * Store 'import_cancel' (config/cache.php) sengaja dipakai, BUKAN Cache default — store itu
 * pakai koneksi DB terpisah ('mysql_progress') supaya pembacaan flag ini dari DALAM transaksi
 * besar MasterImportService tidak kena snapshot REPEATABLE READ transaksi tsb (lihat komentar
 * di config/cache.php & config/database.php).
 */
trait HasCancelableImport
{
    private function importCacheStore(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store('import_cancel');
    }

    private function importCacheKey(string $suffix): string
    {
        return sprintf('import:%s:%s:%s', $this->getTable(), $this->getKey(), $suffix);
    }

    /** TTL longgar (6 jam) — jauh di atas timeout job manapun (maks 1800 detik), cuma jaring pengaman. */
    private function importCacheTtl(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function isCancelRequested(): bool
    {
        return $this->importCacheStore()->has($this->importCacheKey('cancel_requested'));
    }

    /** Tandai batch diminta batal. Dipanggil dari endpoint controller (request HTTP terpisah dari job yang jalan). */
    public function requestCancel(?string $canceledBy = null): void
    {
        $this->importCacheStore()->put($this->importCacheKey('cancel_requested'), $canceledBy ?: 'pengguna', $this->importCacheTtl());
    }

    public function cancelRequestedBy(): ?string
    {
        return $this->importCacheStore()->get($this->importCacheKey('cancel_requested'));
    }

    public function clearCancelRequest(): void
    {
        $this->importCacheStore()->forget($this->importCacheKey('cancel_requested'));
    }

    /** Fase halus dalam satu `status` DB (mis. 'processing') — dipakai batch yang belum punya kolom `phase` asli. */
    public function getCachedPhase(): ?string
    {
        return $this->importCacheStore()->get($this->importCacheKey('phase'));
    }

    public function setCachedPhase(string $phase): void
    {
        $this->importCacheStore()->put($this->importCacheKey('phase'), $phase, $this->importCacheTtl());
    }

    public function clearCachedPhase(): void
    {
        $this->importCacheStore()->forget($this->importCacheKey('phase'));
    }
}
