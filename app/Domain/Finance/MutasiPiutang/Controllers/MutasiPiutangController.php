<?php

namespace App\Domain\Finance\MutasiPiutang\Controllers;

use App\Domain\Finance\MutasiPiutang\Services\MutasiPiutangService;
use App\Http\Controllers\Controller;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MutasiPiutangController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly MutasiPiutangService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'periode_awal'  => ['nullable', 'date'],
            'periode_akhir' => ['nullable', 'date', 'after_or_equal:periode_awal'],
            'klien_ar_id'   => ['nullable', 'integer', 'exists:tb_klien_ar,id'],
        ]);

        $report = $this->service->getReport(
            $request->only(['periode_awal', 'periode_akhir', 'klien_ar_id'])
        );

        return $this->successResponse($report);
    }
}
