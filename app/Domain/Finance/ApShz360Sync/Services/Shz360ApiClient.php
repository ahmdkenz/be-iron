<?php

namespace App\Domain\Finance\ApShz360Sync\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class Shz360ApiClient
{
    public function getPurchaseOrders(array $params = []): array
    {
        return $this->getAllPages('/api/iron/ap/purchase-orders', $params);
    }

    public function getTerimaPos(array $params = []): array
    {
        return $this->getAllPages('/api/iron/ap/terima-pos', $params);
    }

    private function getAllPages(string $path, array $params): array
    {
        $all = [];
        $page = 1;
        $lastPage = 1;

        do {
            $response = $this->request()
                ->get($this->baseUrl() . $path, [...$params, 'page' => $page, 'per_page' => $params['per_page'] ?? 50]);
            $response->throw();

            $json = $response->json();
            $all = array_merge($all, $json['data'] ?? []);
            $lastPage = (int) ($json['meta']['last_page'] ?? 1);
            $page++;
        } while ($page <= $lastPage);

        return $all;
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) config('services.shz360.token'))
            ->timeout((int) config('services.shz360.timeout', 15))
            ->retry(3, 1000);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.shz360.base_url'), '/');
    }
}
