<?php

namespace App\Domain\Finance\TagihanAp\Controllers;

use App\Domain\Finance\TagihanAp\DTO\TagihanApDTO;
use App\Domain\Finance\TagihanAp\Requests\StoreTagihanApRequest;
use App\Domain\Finance\TagihanAp\Requests\UpdateTagihanApRequest;
use App\Domain\Finance\TagihanAp\Resources\TagihanApResource;
use App\Domain\Finance\TagihanAp\Services\TagihanApService;
use App\Http\Controllers\Controller;
use App\Models\TagihanAp;
use App\Models\VendorAp;
use App\Support\Helpers\ApFilterScope;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function approvalQueue(Request $request): JsonResponse
    {
        $this->authorizeApprove();

        $user    = auth()->user();
        $filters = $request->only(['search', 'vendor_ap_id', 'per_page']);
        $filters['approval_status']    = 'PENDING';
        $filters['is_opening_balance'] = false;
        ApFilterScope::apply($filters, $user);

        $list = $this->service->paginate($filters);

        return $this->paginatedResponse(
            $list->through(fn($t) => new TagihanApResource($t))
        );
    }

    public function previewNo(Request $request): JsonResponse
    {
        $this->authorizeOperate();

        $payload = $request->validate([
            'vendor_ap_id' => ['required', 'integer', 'exists:tb_vendor_ap,id'],
            'tanggal'      => ['required', 'date'],
        ]);

        $vendor = VendorAp::findOrFail((int) $payload['vendor_ap_id']);
        $karyawan = auth()->user()->loadMissing('karyawan.perusahaan')->karyawan;

        return $this->successResponse([
            'no_tagihan' => $this->service->generateNoTagihan($vendor, $payload['tanggal'], $karyawan?->perusahaan?->nama_singkatan_perusahaan),
        ]);
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

    public function update(UpdateTagihanApRequest $request, int $id): JsonResponse
    {
        $this->authorizeOperate();

        $tagihan = $this->service->findOrFail($id);
        $updated = $this->service->update($tagihan, TagihanApDTO::fromRequest($request->validated()));
        return $this->successResponse(new TagihanApResource($updated), 'Tagihan berhasil diperbarui');
    }

    public function resubmit(Request $request, int $id): JsonResponse
    {
        $this->authorizeOperate();

        $payload = $request->validate(['note' => ['nullable', 'string']]);
        $tagihan = $this->service->findOrFail($id);
        $updated = $this->service->resubmit($tagihan, $payload['note'] ?? null);
        return $this->successResponse(new TagihanApResource($updated), 'Tagihan berhasil diajukan ulang');
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $this->authorizeApprove();

        $payload = $request->validate(['note' => ['nullable', 'string']]);
        $tagihan = $this->service->findOrFail($id);
        $updated = $this->service->approve($tagihan, $payload['note'] ?? null);
        return $this->successResponse(new TagihanApResource($updated), 'Tagihan berhasil disetujui');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $this->authorizeApprove();

        $payload = $request->validate(['note' => ['required', 'string']]);
        $tagihan = $this->service->findOrFail($id);
        $updated = $this->service->reject($tagihan, $payload['note']);
        return $this->successResponse(new TagihanApResource($updated), 'Tagihan berhasil ditolak');
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
        $rows    = $tagihan->pembayarans()->with('createdBy')->get();

        return $this->successResponse($rows->map(fn($p) => [
            'id'                 => $p->id,
            'tanggal_pembayaran' => $p->tanggal_pembayaran?->format('d-m-Y'),
            'jumlah_pembayaran'  => (float) $p->jumlah_pembayaran,
            'metode_pembayaran'  => $p->metode_pembayaran,
            'no_referensi'       => $p->no_referensi,
            'keterangan'         => $p->keterangan,
            'created_by_name'    => $p->createdBy?->username,
            'created_at'         => $p->created_at?->toIso8601String(),
            'bukti_file_name'    => $p->bukti_file_name,
            'bukti_mime_type'    => $p->bukti_mime_type,
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

    private function authorizeApprove(): void
    {
        abort_if(!RoleHelper::canApproveTagihanAp(auth()->user()), 403, 'Tidak memiliki akses untuk menyetujui tagihan');
    }
}
