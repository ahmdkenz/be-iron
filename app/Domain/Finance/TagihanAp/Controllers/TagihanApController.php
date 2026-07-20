<?php

namespace App\Domain\Finance\TagihanAp\Controllers;

use App\Domain\Finance\TagihanAp\DTO\TagihanApDTO;
use App\Domain\Finance\TagihanAp\Requests\StoreTagihanApRequest;
use App\Domain\Finance\TagihanAp\Requests\UpdateTagihanApRequest;
use App\Domain\Finance\TagihanAp\Resources\TagihanApResource;
use App\Domain\Finance\TagihanAp\Services\TagihanApService;
use App\Http\Controllers\Controller;
use App\Models\TagihanAp;
use App\Support\Helpers\ApFilterScope;
use App\Support\Helpers\RoleHelper;
use App\Support\Helpers\SignatureBarcodeHelper;
use App\Support\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TagihanApController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TagihanApService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView();

        $user    = auth()->user();
        $filters = $request->only([
            'search', 'status', 'approval_status', 'vendor_ap_id', 'karyawan_id',
            'tanggal_dari', 'tanggal_sampai', 'per_page',
        ]);
        $filters['is_opening_balance'] = false;
        ApFilterScope::apply($filters, $user);

        $list = $this->service->paginate($filters);

        return $this->paginatedResponse(
            $list->through(fn($t) => new TagihanApResource($t))
        );
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorizeView();

        $user    = auth()->user();
        $filters = $request->only(['search', 'status', 'approval_status', 'vendor_ap_id', 'tanggal_dari', 'tanggal_sampai']);
        $filters['is_opening_balance'] = false;
        ApFilterScope::apply($filters, $user);

        return $this->successResponse($this->service->getSummary($filters));
    }

    public function previewNo(Request $request): JsonResponse
    {
        $this->authorizeOperate();

        $payload = $request->validate([
            'vendor_ap_id' => ['required', 'integer', 'exists:tb_vendor_ap,id'],
            'tanggal'      => ['required', 'date'],
        ]);

        return $this->successResponse([
            'no_tagihan' => $this->service->generateNoTagihan($payload['tanggal']),
        ]);
    }

    public function outstanding(Request $request): JsonResponse
    {
        $this->authorizeOperate();

        $validated = $request->validate([
            'vendor_ap_id' => ['nullable', 'integer', 'exists:tb_vendor_ap,id'],
            'search'       => ['nullable', 'string', 'max:100'],
            'tanggal'      => ['nullable', 'date'],
        ]);

        $user    = auth()->user();
        $filters = [];
        ApFilterScope::apply($filters, $user);

        $query = TagihanAp::with(['items.barang', 'vendorAp'])
            ->whereIn('status', ['DITERIMA', 'SEBAGIAN'])
            ->where('is_opening_balance', false)
            ->when($validated['vendor_ap_id'] ?? null, fn($q, $v) => $q->where('vendor_ap_id', $v))
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('perusahaan_id', $v))
            ->when($filters['pic_ap_karyawan_id'] ?? null, fn($q, $v) =>
                $q->whereHas('vendorAp', fn($q) => $q->where('karyawan_ap_id', $v))
            )
            ->when($validated['search'] ?? null, fn($q, $v) => $q->where(fn($q) => $q
                ->where('no_tagihan', 'like', "%{$v}%")
                ->orWhere('no_invoice_vendor', 'like', "%{$v}%")
                ->orWhereHas('vendorAp', fn($q) => $q
                    ->where('nama_vendor', 'like', "%{$v}%")
                    ->orWhere('kode_vendor', 'like', "%{$v}%")
                )
            ));

        if (!empty($validated['tanggal'])) {
            $query->where('tanggal_tagihan', '<', $validated['tanggal']);
        }

        $tagihanList = $query->orderBy('tanggal_tagihan')->orderBy('id')->get();

        // Fallback: item lama bisa punya barang_id kosong tapi kode_barang valid.
        // Cari master barang berdasarkan kode_barang agar tetap tersambung.
        $kodeBarangTanpaId = $tagihanList->flatMap(fn($t) => $t->items)
            ->whereNull('barang_id')
            ->pluck('kode_barang')
            ->filter()
            ->unique()
            ->values();

        $barangByKode = $kodeBarangTanpaId->isNotEmpty()
            ? \App\Models\Barang::whereIn('kode_barang', $kodeBarangTanpaId)->get()->keyBy('kode_barang')
            : collect();

        $tagihanList = $tagihanList->map(fn($t) => [
                'id'                => $t->id,
                'no_tagihan'        => $t->no_tagihan,
                'no_invoice_vendor' => $t->no_invoice_vendor,
                'tanggal_tagihan'   => $t->tanggal_tagihan?->toDateString(),
                'subtotal'          => (float) $t->subtotal,
                'total_tagihan'     => (float) $t->total_tagihan,
                'sisa_tagihan'      => max(0.0, (float) $t->sisa_tagihan),
                'status'            => $t->status,
                'keterangan'        => $t->keterangan,
                'vendor_ap_id'      => $t->vendor_ap_id,
                'vendor'            => $t->vendorAp?->nama_vendor,
                'kode_vendor'       => $t->vendorAp?->kode_vendor,
                'items'             => $t->items->map(function ($item) use ($barangByKode) {
                    $fallbackBarang = !$item->barang_id && $item->kode_barang
                        ? $barangByKode->get($item->kode_barang)
                        : null;

                    return [
                        'barang_id'    => $item->barang_id ?? $fallbackBarang?->id,
                        'kode_barang'  => $item->kode_barang ?? $item->barang?->kode_barang ?? '',
                        'nama_barang'  => $item->nama_barang,
                        'qty'          => (float) $item->qty,
                        'satuan'       => $item->satuan ?? 'pcs',
                        'harga_satuan' => (float) $item->harga_satuan,
                        'subtotal'     => (float) $item->subtotal,
                        'keterangan'   => $item->keterangan ?? '',
                    ];
                })->values()->all(),
            ]);

        return $this->successResponse($tagihanList);
    }

    public function store(StoreTagihanApRequest $request): JsonResponse
    {
        $this->authorizeOperate();

        $tagihan = $this->service->create(TagihanApDTO::fromRequest($request->validated()));
        return $this->createdResponse(new TagihanApResource($tagihan), 'Tagihan berhasil diajukan untuk persetujuan');
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeView();

        $tagihan = $this->service->findOrFail($id);
        return $this->successResponse(new TagihanApResource($tagihan));
    }

    public function print(Request $request, int $id): Response|string
    {
        $this->authorizeView();

        $tagihan = $this->service->findForPrintOrFail($id);
        $viewData = $this->buildPrintViewData($tagihan);

        $filename = 'Tagihan-AP-' . str_replace(['/', '\\', ' '], '-', $tagihan->no_tagihan) . '.pdf';

        if ($request->has('html')) {
            return view('finance.tagihan-ap-print', $viewData)->render();
        }

        return Pdf::loadView('finance.tagihan-ap-print', $viewData)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => false,
                'defaultFont'          => 'Arial',
                'dpi'                  => 96,
            ])
            ->stream($filename);
    }

    public function publicPrint(string $token): Response
    {
        $tagihan = TagihanAp::with([
            'vendorAp', 'perusahaan', 'karyawan.perusahaan', 'items', 'pembayarans',
            'createdBy.karyawan', 'submittedBy.karyawan', 'approvedBy.karyawan',
            'openingBalanceApDetails.items',
        ])
            ->where('prepared_token', $token)
            ->firstOrFail();
        $viewData = $this->buildPrintViewData($tagihan);

        $filename = 'Tagihan-AP-' . str_replace(['/', '\\', ' '], '-', $tagihan->no_tagihan) . '.pdf';

        return Pdf::loadView('finance.tagihan-ap-print', $viewData)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => false,
                'defaultFont'          => 'Arial',
                'dpi'                  => 96,
            ])
            ->stream($filename);
    }

    private function buildPrintViewData(TagihanAp $tagihan): array
    {
        abort_if(
            $tagihan->is_opening_balance && $tagihan->approval_status !== 'APPROVED',
            422,
            'Opening balance AP belum disetujui, dokumen belum dapat dicetak'
        );

        $signatureData = $this->buildSignatureData($tagihan);

        $tagihanBerjalanInPeriod = collect();
        if ($tagihan->is_opening_balance && $tagihan->vendor_ap_id && $tagihan->tanggal_tagihan) {
            $bulanAwal  = \Carbon\Carbon::parse($tagihan->tanggal_tagihan)->startOfMonth();
            $bulanAkhir = \Carbon\Carbon::parse($tagihan->tanggal_tagihan)->endOfMonth();

            $tagihanBerjalanInPeriod = TagihanAp::query()
                ->where('vendor_ap_id', $tagihan->vendor_ap_id)
                ->where('perusahaan_id', $tagihan->perusahaan_id)
                ->where('is_opening_balance', false)
                ->whereBetween('tanggal_tagihan', [$bulanAwal->toDateString(), $bulanAkhir->toDateString()])
                ->orderBy('tanggal_tagihan', 'asc')
                ->get();
        }

        if ($tagihanBerjalanInPeriod->isNotEmpty()) {
            $tagihanBerjalanInPeriod->load([
                'vendorAp', 'perusahaan', 'karyawan.perusahaan', 'items.barang',
                'createdBy.karyawan', 'submittedBy.karyawan',
            ]);
        }

        $tagihanBerjalanSignatureData = $tagihanBerjalanInPeriod
            ->mapWithKeys(fn ($t) => [$t->id => $this->buildSignatureData($t)])
            ->all();

        return compact('tagihan', 'signatureData', 'tagihanBerjalanInPeriod', 'tagihanBerjalanSignatureData');
    }

    private function buildSignatureData(TagihanAp $tagihan): array
    {
        $preparedByUser = $tagihan->submittedBy ?: $tagihan->createdBy;
        $preparedByName = $preparedByUser?->karyawan?->nama_karyawan
            ?? $preparedByUser?->username
            ?? '___________________';

        $preparedPayload = $tagihan->is_opening_balance
            ? SignatureBarcodeHelper::buildOpeningBalanceApPreparedPayload($tagihan, $preparedByName)
            : SignatureBarcodeHelper::buildTagihanApPreparedPayload($tagihan, $preparedByName);

        $signatureData = [
            'prepared_by_name' => $preparedByName,
            'prepared_qr_src'  => SignatureBarcodeHelper::generateDataUri($preparedPayload, 250),
        ];

        if ($tagihan->is_opening_balance && $tagihan->approvedBy) {
            $approvedByName = $tagihan->approvedBy->karyawan?->nama_karyawan
                ?? $tagihan->approvedBy->username
                ?? '___________________';

            $approvedPayload = SignatureBarcodeHelper::buildOpeningBalanceApApprovedPayload($tagihan, $approvedByName);

            $signatureData['approved_by_name'] = $approvedByName;
            $signatureData['approved_qr_src']  = SignatureBarcodeHelper::generateDataUri($approvedPayload, 250);
        }

        return $signatureData;
    }

    public function update(UpdateTagihanApRequest $request, int $id): JsonResponse
    {
        $this->authorizeOperate();

        $tagihan = $this->service->findOrFail($id);
        $updated = $this->service->update($tagihan, TagihanApDTO::fromRequest($request->validated()));
        return $this->successResponse(new TagihanApResource($updated), 'Tagihan berhasil diperbarui');
    }

    public function approvalLogs(int $id): JsonResponse
    {
        $this->authorizeView();

        $tagihan = TagihanAp::findOrFail($id);
        $logs    = $tagihan->approvalLogs()->with('actor')->get();

        return $this->successResponse($logs->map(fn($log) => [
            'id'         => $log->id,
            'action'     => $log->action,
            'note'       => $log->note,
            'actor_id'   => $log->actor_id,
            'actor_name' => $log->actor?->username,
            'created_at' => $log->created_at?->toIso8601String(),
        ])->values());
    }

    public function pembayaran(int $id): JsonResponse
    {
        $this->authorizeView();

        $tagihan = TagihanAp::findOrFail($id);
        $rows    = $tagihan->pembayaranApItems()->with('pembayaranAp.createdBy')->get();

        return $this->successResponse($rows->map(fn($item) => [
            'id'                 => $item->pembayaranAp->id,
            'tanggal_pembayaran' => $item->pembayaranAp->tanggal_pembayaran?->format('d-m-Y'),
            'jumlah_pembayaran'  => (float) $item->jumlah_dialokasikan,
            'metode_pembayaran'  => $item->pembayaranAp->metode_pembayaran,
            'no_referensi'       => $item->pembayaranAp->no_referensi,
            'keterangan'         => $item->pembayaranAp->keterangan,
            'created_by_name'    => $item->pembayaranAp->createdBy?->username,
            'created_at'         => $item->pembayaranAp->created_at?->toIso8601String(),
            'bukti_file_name'    => $item->pembayaranAp->bukti_file_name,
            'bukti_mime_type'    => $item->pembayaranAp->bukti_mime_type,
        ]));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeOperate();

        $tagihan = $this->service->findOrFail($id);
        $this->service->delete($tagihan);
        return $this->successResponse(null, 'Tagihan berhasil dihapus');
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->authorizeOperate();

        $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = $this->service->bulkDelete($request->ids);

        return $this->successResponse(
            ['deleted' => $deleted],
            "{$deleted} tagihan berhasil dihapus"
        );
    }

    private function authorizeView(): void
    {
        abort_if(!RoleHelper::canViewTagihanAp(auth()->user()), 403, 'Tidak memiliki akses ke data tagihan');
    }

    private function authorizeOperate(): void
    {
        abort_if(!RoleHelper::canOperateTagihanAp(auth()->user()), 403, 'Tidak memiliki akses untuk mengelola tagihan');
    }

}
