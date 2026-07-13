<?php

namespace App\Domain\Finance\PembayaranAp\Controllers;

use App\Domain\Finance\PembayaranAp\Requests\StorePembayaranApRequest;
use App\Domain\Finance\PembayaranAp\Resources\PembayaranApResource;
use App\Domain\Finance\PembayaranAp\Services\PembayaranApService;
use App\Domain\Finance\TagihanAp\Services\TagihanApService;
use App\Http\Controllers\Controller;
use App\Models\PembayaranAp;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PembayaranApController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PembayaranApService $service,
        private readonly TagihanApService $tagihanApService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView();

        $user = auth()->user()->load('karyawan');

        $query = PembayaranAp::with([
            'tagihanAp.vendorAp',
            'tagihanAp.perusahaan',
            'tagihanAp.karyawan',
            'createdBy.karyawan',
        ])
            ->when($request->vendor_ap_id, fn($q, $v) =>
                $q->whereHas('tagihanAp', fn($q) => $q->where('vendor_ap_id', $v))
            )
            ->when($request->metode_pembayaran, fn($q, $v) => $q->where('metode_pembayaran', $v))
            ->when($request->tanggal_dari, fn($q, $v) => $q->whereDate('tanggal_pembayaran', '>=', $v))
            ->when($request->tanggal_sampai, fn($q, $v) => $q->whereDate('tanggal_pembayaran', '<=', $v));

        if ($user->karyawan && !RoleHelper::hasGlobalApAccess($user)) {
            $query->whereHas('tagihanAp', fn($q) =>
                $q->where('perusahaan_id', $user->karyawan->perusahaan_id)
            );
        }

        if (RoleHelper::isApStaff($user) && $user->karyawan) {
            $query->whereHas('tagihanAp.vendorAp', fn($q) =>
                $q->where('karyawan_ap_id', $user->karyawan->id)
            );
        }

        $totalJumlah = (clone $query)->sum('jumlah_pembayaran');
        $list        = $query->latest('tanggal_pembayaran')->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data'    => $list->through(fn($p) => new PembayaranApResource($p))->items(),
            'meta'    => [
                'current_page' => $list->currentPage(),
                'last_page'    => $list->lastPage(),
                'per_page'     => $list->perPage(),
                'total'        => $list->total(),
                'total_jumlah' => $totalJumlah,
            ],
        ]);
    }

    public function store(StorePembayaranApRequest $request, int $tagihanId): JsonResponse
    {
        $this->authorizeOperate();

        $tagihan = $this->tagihanApService->findOrFail($tagihanId);

        $user = $request->user()->loadMissing('karyawan');
        if (!RoleHelper::hasGlobalApAccess($user) && $user->karyawan) {
            abort_if(
                $tagihan->perusahaan_id !== $user->karyawan->perusahaan_id,
                403,
                'Anda tidak memiliki akses untuk mencatat pembayaran tagihan ini.'
            );
        }

        $pembayaran = $this->service->create(
            $tagihan,
            $request->validated(),
            $request->file('bukti_pembayaran'),
        );

        Log::channel('security')->info('Pembayaran AP dicatat', [
            'user_id'    => $user->id,
            'tagihan_id' => $tagihan->id,
            'no_tagihan' => $tagihan->no_tagihan,
            'jumlah'     => $request->jumlah_pembayaran,
            'metode'     => $request->metode_pembayaran,
            'ip'         => $request->ip(),
        ]);

        return $this->createdResponse(
            new PembayaranApResource($pembayaran->load(['tagihanAp.vendorAp', 'tagihanAp.perusahaan', 'tagihanAp.karyawan', 'createdBy.karyawan'])),
            'Pembayaran berhasil dicatat'
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeOperate();

        $pembayaran = PembayaranAp::with('tagihanAp')->find($id);
        abort_if(!$pembayaran, 404, 'Data pembayaran tidak ditemukan');

        $this->service->delete($pembayaran);
        return $this->successResponse(null, 'Pembayaran berhasil dihapus');
    }

    public function publicBukti(PembayaranAp $pembayaran): StreamedResponse
    {
        abort_if(!$pembayaran->bukti_path, 404);

        return Storage::disk($pembayaran->bukti_disk)->response(
            $pembayaran->bukti_path,
            $pembayaran->bukti_file_name,
            ['Content-Type' => $pembayaran->bukti_mime_type],
        );
    }

    public function cekReferensi(Request $request): JsonResponse
    {
        $this->authorizeView();

        $request->validate([
            'no_referensi' => ['required', 'string', 'max:100'],
        ]);

        $result = $this->service->cekDuplikatReferensi($request->no_referensi);

        return $this->successResponse($result);
    }

    private function authorizeView(): void
    {
        abort_if(!RoleHelper::canViewPembayaranAp(auth()->user()), 403, 'Tidak memiliki akses ke data pembayaran');
    }

    private function authorizeOperate(): void
    {
        abort_if(!RoleHelper::canOperatePembayaranAp(auth()->user()), 403, 'Tidak memiliki akses untuk mengelola pembayaran');
    }
}
