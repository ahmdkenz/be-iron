<?php

namespace App\Domain\Finance\OpeningBalanceAp\Controllers;

use App\Domain\Finance\OpeningBalanceAp\Requests\StoreOpeningBalanceApRequest;
use App\Domain\Finance\OpeningBalanceAp\Resources\OpeningBalanceApDetailResource;
use App\Domain\Finance\OpeningBalanceAp\Resources\OpeningBalanceApResource;
use App\Domain\Finance\TagihanAp\Services\TagihanApService;
use App\Http\Controllers\Controller;
use App\Models\TagihanAp;
use App\Support\Helpers\ApFilterScope;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OpeningBalanceApController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TagihanApService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView();

        $user    = auth()->user();
        $filters = $request->only([
            'search', 'status', 'vendor_ap_id', 'karyawan_id',
            'tanggal_dari', 'tanggal_sampai', 'approval_status', 'per_page',
        ]);
        $filters['is_opening_balance'] = true;
        ApFilterScope::apply($filters, $user);

        $list = $this->service->paginate($filters, with: [
            'vendorAp', 'perusahaan', 'karyawan',
            'submittedBy', 'createdBy', 'updatedBy',
        ]);

        return $this->paginatedResponse(
            $list->through(fn($tagihan) => new OpeningBalanceApResource($tagihan))
        );
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorizeView();

        $user    = auth()->user();
        $filters = $request->only([
            'search', 'status', 'vendor_ap_id', 'tanggal_dari', 'tanggal_sampai', 'approval_status',
        ]);
        $filters['is_opening_balance'] = true;
        ApFilterScope::apply($filters, $user);

        return $this->successResponse($this->service->getSummary($filters));
    }

    public function previewNo(Request $request): JsonResponse
    {
        $this->authorizeOperate();

        $payload = $request->validate([
            'tanggal' => ['required', 'date'],
        ]);

        return $this->successResponse([
            'no_tagihan' => $this->service->generateOpeningBalanceNoTagihan($payload['tanggal']),
        ]);
    }

    public function store(StoreOpeningBalanceApRequest $request): JsonResponse
    {
        $this->authorizeOperate();

        $tagihan = $this->service->createOpeningBalance($request->validated());

        return $this->createdResponse(
            new OpeningBalanceApResource($tagihan),
            'Opening balance AP berhasil diajukan untuk persetujuan'
        );
    }

    public function update(StoreOpeningBalanceApRequest $request, int $id): JsonResponse
    {
        $this->authorizeOperate();

        $tagihan = $this->findOpeningBalanceOrFail($id);
        $updated = $this->service->updateOpeningBalance($tagihan, $request->validated());

        return $this->successResponse(
            new OpeningBalanceApResource($updated),
            'Opening balance AP berhasil diperbarui'
        );
    }

    public function details(int $id): JsonResponse
    {
        $this->authorizeView();

        $tagihan = $this->findOpeningBalanceOrFail($id);

        return $this->successResponse(
            OpeningBalanceApDetailResource::collection(
                $tagihan->openingBalanceApDetails()->with('items.barang')->orderBy('tanggal_invoice_asal')->get()
            )
        );
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $this->authorizeApprove();

        $payload = $request->validate([
            'note' => ['nullable', 'string'],
        ]);

        $tagihan = $this->findOpeningBalanceOrFail($id);
        $updated = $this->service->approveOpeningBalance($tagihan, $payload['note'] ?? null);

        Log::channel('security')->info('Opening balance AP disetujui', [
            'user_id'    => auth()->id(),
            'tagihan_id' => $tagihan->id,
            'no_tagihan' => $tagihan->no_tagihan,
            'ip'         => $request->ip(),
        ]);

        return $this->successResponse(
            new OpeningBalanceApResource($updated),
            'Opening balance AP berhasil disetujui'
        );
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $this->authorizeApprove();

        $payload = $request->validate([
            'note' => ['required', 'string'],
        ]);

        $tagihan = $this->findOpeningBalanceOrFail($id);
        $updated = $this->service->rejectOpeningBalance($tagihan, $payload['note']);

        Log::channel('security')->info('Opening balance AP ditolak', [
            'user_id'    => auth()->id(),
            'tagihan_id' => $tagihan->id,
            'no_tagihan' => $tagihan->no_tagihan,
            'note'       => $payload['note'],
            'ip'         => $request->ip(),
        ]);

        return $this->successResponse(
            new OpeningBalanceApResource($updated),
            'Opening balance AP berhasil ditolak'
        );
    }

    public function resubmit(Request $request, int $id): JsonResponse
    {
        $this->authorizeOperate();

        $payload = $request->validate([
            'note' => ['nullable', 'string'],
        ]);

        $tagihan = $this->findOpeningBalanceOrFail($id);
        $updated = $this->service->resubmitOpeningBalance($tagihan, $payload['note'] ?? null);

        return $this->successResponse(
            new OpeningBalanceApResource($updated),
            'Opening balance AP berhasil diajukan ulang'
        );
    }

    private function findOpeningBalanceOrFail(int $id): TagihanAp
    {
        $tagihan = $this->service->findOrFail($id);
        abort_if(!$tagihan->is_opening_balance, 404, 'Opening balance AP tidak ditemukan');

        return $tagihan;
    }

    private function authorizeView(): void
    {
        abort_if(
            !RoleHelper::canViewOpeningBalanceAp(auth()->user()),
            403,
            'Tidak memiliki akses ke data opening balance AP'
        );
    }

    private function authorizeOperate(): void
    {
        abort_if(
            !RoleHelper::canOperateOpeningBalanceAp(auth()->user()),
            403,
            'Tidak memiliki akses untuk mengelola opening balance AP'
        );
    }

    private function authorizeApprove(): void
    {
        abort_if(
            !RoleHelper::canApproveOpeningBalanceAp(auth()->user()),
            403,
            'Tidak memiliki akses untuk menyetujui opening balance AP'
        );
    }
}
