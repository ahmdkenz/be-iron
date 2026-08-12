<?php

namespace App\Domain\Quran\Surah\Controllers;

use App\Domain\Quran\Surah\Services\EquranApiClient;
use App\Http\Controllers\Controller;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class SurahController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly EquranApiClient $equran)
    {
    }

    public function index(): JsonResponse
    {
        $surah = $this->equran->listSurah();

        if ($surah === null) {
            return $this->errorResponse('Gagal memuat daftar surah, coba lagi sebentar lagi', 502);
        }

        return $this->successResponse($surah);
    }

    public function show(int $nomor): JsonResponse
    {
        $surah = $this->equran->getSurah($nomor);

        if ($surah === null) {
            return $this->notFoundResponse('Surah tidak ditemukan');
        }

        return $this->successResponse($surah);
    }
}
