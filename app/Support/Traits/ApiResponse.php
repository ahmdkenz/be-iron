<?php

namespace App\Support\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    protected function successResponse(mixed $data = null, string $message = 'Success', int $statusCode = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $statusCode);
    }

    protected function createdResponse(mixed $data = null, string $message = 'Data berhasil dibuat'): JsonResponse
    {
        return $this->successResponse($data, $message, 201);
    }

    protected function errorResponse(string $message = 'Terjadi kesalahan', int $statusCode = 400, mixed $errors = null): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $statusCode);
    }

    protected function notFoundResponse(string $message = 'Data tidak ditemukan'): JsonResponse
    {
        return $this->errorResponse($message, 404);
    }

    protected function unauthorizedResponse(string $message = 'Tidak memiliki akses'): JsonResponse
    {
        return $this->errorResponse($message, 403);
    }

    protected function paginatedResponse(mixed $paginator, string $message = 'Success'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $paginator->items(),
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Paginasi array PHP yang sudah dimuat penuh di memori (mis. hasil laporan
     * agregat). Dipakai saat query dasarnya sudah harus mengambil semua baris
     * untuk menghitung summary, sehingga hanya bagian pengiriman ke frontend
     * yang dipotong per halaman.
     */
    protected function paginateArray(array $items, int $page, int $perPage): array
    {
        $total = count($items);

        return [
            'items' => array_values(array_slice($items, ($page - 1) * $perPage, $perPage)),
            'meta'  => [
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($total / $perPage)),
                'per_page'     => $perPage,
                'total'        => $total,
            ],
        ];
    }
}
