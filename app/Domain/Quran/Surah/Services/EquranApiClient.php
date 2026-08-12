<?php

namespace App\Domain\Quran\Surah\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EquranApiClient
{
    /**
     * @return array<int, array<string, mixed>>|null null jika API luar gagal dihubungi
     */
    public function listSurah(): ?array
    {
        try {
            $response = $this->request()->get($this->baseUrl() . '/surat');

            if (!$response->successful()) {
                Log::warning('EquranApiClient listSurah gagal', ['status' => $response->status()]);

                return null;
            }

            return $response->json('data') ?? [];
        } catch (\Throwable $e) {
            Log::error('EquranApiClient listSurah exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null null jika surah tidak ditemukan atau API luar gagal dihubungi
     */
    public function getSurah(int $nomor): ?array
    {
        try {
            $response = $this->request()->get($this->baseUrl() . '/surat/' . $nomor);

            if (!$response->successful()) {
                if ($response->status() !== 404) {
                    Log::warning('EquranApiClient getSurah gagal', ['nomor' => $nomor, 'status' => $response->status()]);
                }

                return null;
            }

            return $response->json('data');
        } catch (\Throwable $e) {
            Log::error('EquranApiClient getSurah exception', ['nomor' => $nomor, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function request(): PendingRequest
    {
        return Http::timeout((int) config('services.equran.timeout', 15));
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.equran.base_url'), '/');
    }
}
