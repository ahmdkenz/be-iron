<?php

namespace App\Domain\Finance\AgingReport\Controllers;

use App\Domain\Finance\AgingReport\Services\AgingReportService;
use App\Http\Controllers\Controller;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgingReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AgingReportService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'as_of_date'    => ['nullable', 'date'],
            'klien_ar_id'   => ['nullable', 'integer', 'exists:tb_klien_ar,id'],
            'perusahaan_id' => ['nullable', 'integer', 'exists:tb_perusahaan,id'],
        ]);

        $report = $this->service->getReport($request->only(['as_of_date', 'klien_ar_id', 'perusahaan_id']));

        return $this->successResponse($report);
    }
}
