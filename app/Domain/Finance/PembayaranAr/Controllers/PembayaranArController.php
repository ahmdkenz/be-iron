<?php

namespace App\Domain\Finance\PembayaranAr\Controllers;

use App\Domain\Finance\Invoice\Resources\InvoiceResource;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\PembayaranAr\Requests\StorePembayaranArRequest;
use App\Domain\Finance\PembayaranAr\Resources\PembayaranArResource;
use App\Domain\Finance\PembayaranAr\Services\PembayaranArService;
use App\Http\Controllers\Controller;
use App\Models\PembayaranAr;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PembayaranArController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PembayaranArService $service,
        private readonly InvoiceService $invoiceService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = auth()->user()->load('karyawan');

        $query = PembayaranAr::with(['invoice.klienAr', 'invoice.perusahaan', 'createdBy'])
            ->when($request->klien_ar_id, fn($q, $v) =>
                $q->whereHas('invoice', fn($q) => $q->where('klien_ar_id', $v))
            )
            ->when($request->metode_pembayaran, fn($q, $v) => $q->where('metode_pembayaran', $v))
            ->when($request->tanggal_dari, fn($q, $v) => $q->whereDate('tanggal_pembayaran', '>=', $v))
            ->when($request->tanggal_sampai, fn($q, $v) => $q->whereDate('tanggal_pembayaran', '<=', $v));

        if ($user->karyawan && !RoleHelper::hasGlobalFinanceAccess($user)) {
            $query->whereHas('invoice', fn($q) =>
                $q->where('perusahaan_id', $user->karyawan->perusahaan_id)
            );
        }

        $list = $query->latest('tanggal_pembayaran')->paginate($request->per_page ?? 20);

        return $this->paginatedResponse(
            $list->through(fn($p) => new PembayaranArResource($p))
        );
    }

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
