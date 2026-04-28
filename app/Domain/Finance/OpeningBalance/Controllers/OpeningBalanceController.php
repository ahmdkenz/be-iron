<?php

namespace App\Domain\Finance\OpeningBalance\Controllers;

use App\Domain\Finance\Invoice\Resources\InvoiceResource;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\OpeningBalance\Requests\StoreOpeningBalanceRequest;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpeningBalanceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly InvoiceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeViewOpeningBalance();

        $user    = auth()->user()->load('karyawan');
        $filters = $request->only([
            'search', 'status', 'klien_ar_id', 'karyawan_id',
            'periode_bulan', 'periode_tahun', 'approval_status',
        ]);
        $filters['is_opening_balance'] = true;

        if ($user->karyawan && !RoleHelper::hasGlobalFinanceAccess($user)) {
            $filters['perusahaan_id'] = $user->karyawan->perusahaan_id;
        }

        $list = $this->service->paginate($filters);

        return $this->paginatedResponse(
            $list->through(fn($invoice) => new InvoiceResource($invoice))
        );
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorizeViewOpeningBalance();

        $user    = auth()->user()->load('karyawan');
        $filters = $request->only([
            'search', 'status', 'klien_ar_id', 'karyawan_id',
            'periode_bulan', 'periode_tahun', 'approval_status',
        ]);
        $filters['is_opening_balance'] = true;

        if ($user->karyawan && !RoleHelper::hasGlobalFinanceAccess($user)) {
            $filters['perusahaan_id'] = $user->karyawan->perusahaan_id;
        }

        return $this->successResponse($this->service->getSummary($filters));
    }

    public function store(StoreOpeningBalanceRequest $request): JsonResponse
    {
        $this->authorizeOperateOpeningBalance();

        $invoice = $this->service->createOpeningBalance($request->validated());

        return $this->createdResponse(
            new InvoiceResource($invoice),
            'Opening balance berhasil diajukan untuk persetujuan'
        );
    }

    public function update(StoreOpeningBalanceRequest $request, int $id): JsonResponse
    {
        $this->authorizeOperateOpeningBalance();

        $invoice = $this->findOpeningBalanceOrFail($id);
        $updated = $this->service->updateOpeningBalance($invoice, $request->validated());

        return $this->successResponse(
            new InvoiceResource($updated),
            'Opening balance berhasil diperbarui'
        );
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $this->authorizeApproveOpeningBalance();

        $payload = $request->validate([
            'note' => ['nullable', 'string'],
        ]);

        $invoice = $this->findOpeningBalanceOrFail($id);
        $updated = $this->service->approveOpeningBalance($invoice, $payload['note'] ?? null);

        return $this->successResponse(
            new InvoiceResource($updated),
            'Opening balance berhasil disetujui'
        );
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $this->authorizeApproveOpeningBalance();

        $payload = $request->validate([
            'note' => ['required', 'string'],
        ]);

        $invoice = $this->findOpeningBalanceOrFail($id);
        $updated = $this->service->rejectOpeningBalance($invoice, $payload['note']);

        return $this->successResponse(
            new InvoiceResource($updated),
            'Opening balance berhasil ditolak'
        );
    }

    public function resubmit(Request $request, int $id): JsonResponse
    {
        $this->authorizeOperateOpeningBalance();

        $payload = $request->validate([
            'note' => ['nullable', 'string'],
        ]);

        $invoice = $this->findOpeningBalanceOrFail($id);
        $updated = $this->service->resubmitOpeningBalance($invoice, $payload['note'] ?? null);

        return $this->successResponse(
            new InvoiceResource($updated),
            'Opening balance berhasil diajukan ulang'
        );
    }

    private function findOpeningBalanceOrFail(int $id): Invoice
    {
        $invoice = $this->service->findOrFail($id);
        abort_if(!$invoice->is_opening_balance, 404, 'Opening balance tidak ditemukan');

        return $invoice;
    }

    private function authorizeViewOpeningBalance(): void
    {
        abort_if(
            !RoleHelper::canViewOpeningBalance(auth()->user()),
            403,
            'Tidak memiliki akses ke data opening balance'
        );
    }

    private function authorizeOperateOpeningBalance(): void
    {
        abort_if(
            !RoleHelper::canOperateOpeningBalance(auth()->user()),
            403,
            'Tidak memiliki akses untuk mengelola opening balance'
        );
    }

    private function authorizeApproveOpeningBalance(): void
    {
        abort_if(
            !RoleHelper::canApproveOpeningBalance(auth()->user()),
            403,
            'Tidak memiliki akses untuk menyetujui opening balance'
        );
    }
}
