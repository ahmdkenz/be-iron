<?php

namespace App\Domain\Finance\Invoice\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FonnteApiClient
{
    /**
     * Kirim pesan WA ke banyak penerima dengan pesan berbeda per penerima —
     * satu request Fonnte per penerima, karena `target` koma-pisah Fonnte
     * hanya mendukung SATU pesan yang sama untuk semua nomor sekaligus,
     * sementara tiap klien/investor di sini butuh pesan (invoice/link) sendiri.
     * Tiap penerima juga bawa token device Fonnte sendiri (milik PIC AR yang
     * menangani klien tsb) — sudah di-resolve oleh caller sebelum sampai sini.
     *
     * @param array<int, array{target:string,message:string,token:string,label?:?string}> $recipients
     * @return array<int, array{target:string,label:?string,success:bool,detail:?string,fonnte_id:?string}>
     */
    public function blast(array $recipients): array
    {
        $results = [];
        $delayMs = (int) config('services.fonnte.delay_ms', 300);
        $last    = array_key_last($recipients);

        foreach ($recipients as $i => $recipient) {
            $results[] = $this->sendOne($recipient);

            if ($i !== $last && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        return $results;
    }

    private function sendOne(array $recipient): array
    {
        $target = $recipient['target'];
        $label  = $recipient['label'] ?? null;

        try {
            $response = $this->request($recipient['token'])->post($this->baseUrl() . '/send', [
                'target'  => $target,
                'message' => $recipient['message'],
            ]);

            $json    = $response->json() ?? [];
            $success = (bool) ($json['status'] ?? $json['Status'] ?? false);

            if (!$success) {
                Log::warning('Fonnte blast gagal', ['target' => $target, 'response' => $json]);
            }

            return [
                'target'    => $target,
                'label'     => $label,
                'success'   => $success,
                'detail'    => $json['reason'] ?? $json['detail'] ?? null,
                'fonnte_id' => $json['id'][0] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('Fonnte blast exception', ['target' => $target, 'error' => $e->getMessage()]);

            return [
                'target'    => $target,
                'label'     => $label,
                'success'   => false,
                'detail'    => 'Gagal menghubungi layanan WhatsApp: ' . $e->getMessage(),
                'fonnte_id' => null,
            ];
        }
    }

    private function request(string $token): PendingRequest
    {
        return Http::withHeaders(['Authorization' => $token])
            ->timeout((int) config('services.fonnte.timeout', 15));
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.fonnte.base_url'), '/');
    }
}
