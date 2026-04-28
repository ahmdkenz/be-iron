<?php

namespace App\Domain\Finance\PembayaranAr\Controllers;

use App\Domain\Finance\Invoice\Resources\InvoiceResource;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\PembayaranAr\Requests\StorePembayaranArRequest;
use App\Domain\Finance\PembayaranAr\Resources\PembayaranArResource;
use App\Domain\Finance\PembayaranAr\Services\PembayaranArService;
use App\Http\Controllers\Controller;
use App\Models\PembayaranAr;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class PembayaranArController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PembayaranArService $service,
        private readonly InvoiceService $invoiceService,
    ) {}

    public function store(StorePembayaranArRequest $request, int $invoiceId): JsonResponse
    {
        $invoice   = $this->invoiceService->findOrFail($invoiceId);
        $pembayaran = $this->service->create($invoice, $request->validated());

        return $this->createdResponse(
            new PembayaranArResource($pembayaran),
            'Pembayaran berhasil dicatat'
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $pembayaran = PembayaranAr::with('invoice')->find($id);
        abort_if(!$pembayaran, 404, 'Data pembayaran tidak ditemukan');

        $this->service->delete($pembayaran);
        return $this->successResponse(null, 'Pembayaran berhasil dihapus');
    }
}
