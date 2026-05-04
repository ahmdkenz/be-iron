<?php

namespace App\Domain\Finance\JatuhTempo\Controllers;

use App\Domain\Finance\JatuhTempo\Services\JatuhTempoService;
use App\Http\Controllers\Controller;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JatuhTempoController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly JatuhTempoService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'klien_ar_id'    => ['nullable', 'integer', 'exists:tb_klien_ar,id'],
            'karyawan_ar_id' => ['nullable', 'integer', 'exists:tb_karyawan,id'],
            'days'           => ['nullable', 'integer', 'min:1', 'max:365'],
            'filter_type'    => ['nullable', 'in:upcoming,overdue,all'],
        ]);

        $report = $this->service->getReport(
            $request->only(['klien_ar_id', 'karyawan_ar_id', 'days', 'filter_type'])
        );

        return $this->successResponse($report);
    }
}
