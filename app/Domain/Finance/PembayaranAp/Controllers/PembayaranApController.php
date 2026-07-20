<?php

namespace App\Domain\Finance\PembayaranAp\Controllers;

use App\Domain\Finance\PembayaranAp\Requests\StorePembayaranApRequest;
use App\Domain\Finance\PembayaranAp\Requests\StorePembayaranApVoucherRequest;
use App\Domain\Finance\PembayaranAp\Resources\PembayaranApResource;
use App\Domain\Finance\PembayaranAp\Services\PembayaranApService;
use App\Domain\Finance\TagihanAp\Services\TagihanApService;
use App\Http\Controllers\Controller;
use App\Models\PembayaranAp;
use App\Support\Helpers\RoleHelper;
use App\Support\Helpers\Terbilang;
use App\Support\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
            'items.tagihanAp.vendorAp',
            'items.tagihanAp.perusahaan',
            'items.tagihanAp.karyawan',
            'items.vendorAp',
            'createdBy.karyawan',
        ])
            ->when($request->vendor_ap_id, fn($q, $v) =>
                $q->whereHas('items', fn($q) => $q->where('vendor_ap_id', $v))
            )
            ->when($request->metode_pembayaran, fn($q, $v) => $q->where('metode_pembayaran', $v))
            ->when($request->tanggal_dari, fn($q, $v) => $q->whereDate('tanggal_pembayaran', '>=', $v))
            ->when($request->tanggal_sampai, fn($q, $v) => $q->whereDate('tanggal_pembayaran', '<=', $v));

        if ($user->karyawan && !RoleHelper::hasGlobalApAccess($user)) {
            $query->whereHas('items.tagihanAp', fn($q) =>
                $q->where('perusahaan_id', $user->karyawan->perusahaan_id)
            );
        }

        if (RoleHelper::isApStaff($user) && $user->karyawan) {
            $query->whereHas('items.vendorAp', fn($q) =>
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
            new PembayaranApResource($pembayaran->load([
                'items.tagihanAp.vendorAp', 'items.tagihanAp.perusahaan', 'items.tagihanAp.karyawan', 'createdBy.karyawan',
            ])),
            'Pembayaran berhasil dicatat'
        );
    }

    public function storeVoucher(StorePembayaranApVoucherRequest $request): JsonResponse
    {
        $this->authorizeOperate();

        $validated = $request->validated();
        $user      = $request->user()->loadMissing('karyawan');

        if (!RoleHelper::hasGlobalApAccess($user) && $user->karyawan) {
            $tagihanIds = collect($validated['alokasi'])->pluck('tagihan_ap_id')->unique();
            $luarScope  = \App\Models\TagihanAp::whereIn('id', $tagihanIds)
                ->where('perusahaan_id', '!=', $user->karyawan->perusahaan_id)
                ->exists();

            abort_if($luarScope, 403, 'Anda tidak memiliki akses untuk mencatat pembayaran tagihan ini.');
        }

        $pembayaran = $this->service->createVoucher($validated, $request->file('bukti_pembayaran'));

        Log::channel('security')->info('Voucher Pembayaran AP dicatat', [
            'user_id'        => $user->id,
            'pembayaran_id'  => $pembayaran->id,
            'jumlah_total'   => $pembayaran->jumlah_pembayaran,
            'jumlah_tagihan' => count($validated['alokasi']),
            'metode'         => $validated['metode_pembayaran'],
            'ip'             => $request->ip(),
        ]);

        return $this->createdResponse(
            new PembayaranApResource($pembayaran->load([
                'items.tagihanAp.vendorAp', 'items.tagihanAp.perusahaan', 'items.tagihanAp.karyawan', 'createdBy.karyawan',
            ])),
            'Voucher pembayaran berhasil dicatat'
        );
    }

    public function print(Request $request, int $id): Response|string
    {
        $this->authorizeView();

        $pembayaran = PembayaranAp::with([
            'items.tagihanAp.vendorAp',
            'items.tagihanAp.perusahaan',
            'createdBy.karyawan',
        ])->find($id);
        abort_if(!$pembayaran, 404, 'Data pembayaran tidak ditemukan');

        $vendorGroups = $pembayaran->items->groupBy('vendor_ap_id');
        $noVoucher    = $pembayaran->no_referensi ?: ('VP-' . str_pad((string) $pembayaran->id, 6, '0', STR_PAD_LEFT));
        $terbilang    = Terbilang::convert((int) $pembayaran->jumlah_pembayaran);

        $viewData = compact('pembayaran', 'vendorGroups', 'noVoucher', 'terbilang');
        $filename = 'Voucher-AP-' . str_replace(['/', '\\', ' '], '-', $noVoucher) . '.pdf';

        if ($request->has('html')) {
            return view('finance.pembayaran-ap-print', $viewData)->render();
        }

        return Pdf::loadView('finance.pembayaran-ap-print', $viewData)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => false,
                'defaultFont'          => 'Arial',
                'dpi'                  => 96,
            ])
            ->stream($filename);
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
