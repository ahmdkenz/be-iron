<?php

namespace App\Domain\Finance\KinerjaAr\Controllers;

use App\Domain\Finance\KinerjaAr\Services\KinerjaArService;
use App\Http\Controllers\Controller;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KinerjaArController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly KinerjaArService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'periode_awal'   => ['nullable', 'date'],
            'periode_akhir'  => ['nullable', 'date', 'after_or_equal:periode_awal'],
            'karyawan_ar_id' => ['nullable', 'integer', 'exists:tb_karyawan,id'],
        ]);

        $report = $this->service->getReport(
            $request->only(['periode_awal', 'periode_akhir', 'karyawan_ar_id'])
        );

        return $this->successResponse($report);
    }
}
