<?php

namespace App\Domain\Finance\RekeningKoran\Controllers;

use App\Domain\Finance\RekeningKoran\Services\RekeningKoranUmumService;
use App\Http\Controllers\Controller;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RekeningKoranController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RekeningKoranUmumService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'pic_ar_id'        => ['nullable', 'integer', 'exists:tb_users,id'],
            'periode_awal'     => ['nullable', 'date'],
            'periode_akhir'    => ['nullable', 'date', 'after_or_equal:periode_awal'],
            'bank_type'        => ['nullable', 'in:BCA,MANDIRI,CIMB,BSI'],
            'dk'               => ['nullable', 'in:K,D'],
            'status_posting_1' => ['nullable', 'in:MATCHED,UNMATCHED,DIABAIKAN'],
            'status_posting_2' => ['nullable', 'in:POSTED,PENDING'],
            'per_page'         => ['nullable', 'integer', 'min:5', 'max:100'],
            'page'             => ['nullable', 'integer', 'min:1'],
        ]);

        $report = $this->service->getReport(
            $request->only(['pic_ar_id', 'periode_awal', 'periode_akhir', 'bank_type', 'dk', 'status_posting_1', 'status_posting_2']),
            (int) $request->input('per_page', 25),
            (int) $request->input('page', 1),
        );

        return $this->successResponse($report);
    }

    public function picArList(): JsonResponse
    {
        return $this->successResponse($this->service->getPicArList());
    }
}
