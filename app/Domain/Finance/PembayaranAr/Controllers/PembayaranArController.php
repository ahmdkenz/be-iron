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
use Illuminate\Support\Facades\Log;

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

        $segmentTypes = match(strtoupper($request->segment ?? '')) {
            'B2B'   => ['PT'],
            'B2C'   => ['RESTO'],
            default => null,
        };

        $query = PembayaranAr::with(['invoice.klienAr', 'invoice.perusahaan', 'createdBy.karyawan'])
            ->when($request->klien_ar_id, fn($q, $v) =>
                $q->whereHas('invoice', fn($q) => $q->where('klien_ar_id', $v))
            )
            ->when($request->karyawan_id, fn($q, $v) =>
                $q->whereHas('invoice', fn($q) => $q->where('karyawan_id', $v))
            )
            ->when($request->metode_pembayaran, fn($q, $v) => $q->where('metode_pembayaran', $v))
            ->when($request->tanggal_dari, fn($q, $v) => $q->whereDate('tanggal_pembayaran', '>=', $v))
            ->when($request->tanggal_sampai, fn($q, $v) => $q->whereDate('tanggal_pembayaran', '<=', $v))
            ->when($segmentTypes, fn($q) =>
                $q->whereHas('invoice', fn($q) =>
                    $q->whereHas('klienAr', fn($q) => $q->whereIn('tipe_klien', $segmentTypes))
                )
            );

        if ($user->karyawan && !RoleHelper::hasGlobalArAccess($user)) {
            $query->whereHas('invoice', fn($q) =>
                $q->where('perusahaan_id', $user->karyawan->perusahaan_id)
            );
        }

        $totalJumlah = (clone $query)->sum('jumlah_pembayaran');
        $list        = $query->latest('tanggal_pembayaran')->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data'    => $list->through(fn($p) => new PembayaranArResource($p))->items(),
            'meta'    => [
                'current_page' => $list->currentPage(),
                'last_page'    => $list->lastPage(),
                'per_page'     => $list->perPage(),
                'total'        => $list->total(),
                'total_jumlah' => $totalJumlah,
            ],
        ]);
    }

    public function store(StorePembayaranArRequest $request, int $invoiceId): JsonResponse
    {
        $invoice = $this->invoiceService->findOrFail($invoiceId);

        $user = $request->user()->loadMissing('karyawan');
        if (!RoleHelper::hasGlobalArAccess($user) && $user->karyawan) {
            abort_if(
                $invoice->perusahaan_id !== $user->karyawan->perusahaan_id,
                403,
                'Anda tidak memiliki akses untuk mencatat pembayaran invoice ini.'
            );
        }

        $pembayaran = $this->service->create(
            $invoice,
            $request->validated(),
            $request->file('bukti_pembayaran'),
        );

        Log::channel('security')->info('Pembayaran dicatat', [
            'user_id'        => $user->id,
            'invoice_id'     => $invoice->id,
            'no_invoice'     => $invoice->no_invoice,
            'jumlah'         => $request->jumlah_pembayaran,
            'metode'         => $request->metode_pembayaran,
            'ip'             => $request->ip(),
        ]);

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

    public function cekReferensi(Request $request): JsonResponse
    {
        $request->validate([
            'no_referensi' => ['required', 'string', 'max:100'],
        ]);

        $result = $this->service->cekDuplikatReferensi($request->no_referensi);

        return $this->successResponse($result);
    }
}
