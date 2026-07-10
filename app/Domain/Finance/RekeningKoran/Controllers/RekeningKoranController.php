<?php

namespace App\Domain\Finance\RekeningKoran\Controllers;

use App\Domain\Finance\RekeningKoran\Services\RekeningKoranUmumService;
use App\Http\Controllers\Controller;
use App\Models\BankStatementDetail;
use App\Support\Helpers\RoleHelper;
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

    public function updatePosting(Request $request, BankStatementDetail $detail): JsonResponse
    {
        abort_unless(
            RoleHelper::canManageMatchedRecord(auth()->user(), $detail->matched_by),
            403,
            'Hanya PIC AR yang mencocokkan transaksi ini (atau Admin/Manager/Supervisor) yang dapat melakukan posting.'
        );

        $request->validate([
            'status_posting_2' => ['required', 'in:POSTED,PENDING'],
        ]);

        $detail->update([
            'status_posting_2' => $request->status_posting_2,
            'posted_at'        => $request->status_posting_2 === 'POSTED' ? now() : null,
            'posted_by'        => $request->status_posting_2 === 'POSTED' ? auth()->id() : null,
        ]);

        return $this->successResponse(null, 'Status posting berhasil diperbarui.');
    }
}
