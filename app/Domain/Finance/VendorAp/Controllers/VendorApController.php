<?php

namespace App\Domain\Finance\VendorAp\Controllers;

use App\Domain\Finance\VendorAp\DTO\VendorApDTO;
use App\Domain\Finance\VendorAp\Requests\StoreVendorApRequest;
use App\Domain\Finance\VendorAp\Requests\UpdateVendorApRequest;
use App\Domain\Finance\VendorAp\Resources\VendorApResource;
use App\Domain\Finance\VendorAp\Services\VendorApService;
use App\Http\Controllers\Controller;
use App\Support\Helpers\ApFilterScope;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorApController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly VendorApService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView();

        $user    = auth()->user();
        $filters = $request->only(['search', 'status', 'karyawan_ap_id', 'per_page']);
        ApFilterScope::apply($filters, $user);

        if ($request->boolean('all')) {
            return $this->successResponse(VendorApResource::collection($this->service->getAll($filters)));
        }

        $list = $this->service->paginate($filters);

        return $this->paginatedResponse(
            $list->through(fn($v) => new VendorApResource($v))
        );
    }

    public function store(StoreVendorApRequest $request): JsonResponse
    {
        $this->authorizeOperate();

        $vendor = $this->service->create(VendorApDTO::fromRequest($request->validated()));
        return $this->createdResponse(new VendorApResource($vendor), 'Vendor berhasil dibuat');
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeView();

        $vendor = $this->service->findOrFail($id);
        $this->authorizePicApVendor($vendor->karyawan_ap_id);

        return $this->successResponse(new VendorApResource($vendor));
    }

    public function update(UpdateVendorApRequest $request, int $id): JsonResponse
    {
        $this->authorizeOperate();

        $vendor = $this->service->findOrFail($id);
        $this->authorizePicApVendor($vendor->karyawan_ap_id);
        $updated = $this->service->update($vendor, VendorApDTO::fromRequest($request->validated()));
        return $this->successResponse(new VendorApResource($updated), 'Vendor berhasil diperbarui');
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeOperate();

        $vendor = $this->service->findOrFail($id);
        $this->authorizePicApVendor($vendor->karyawan_ap_id);
        $this->service->delete($vendor);
        return $this->successResponse(null, 'Vendor berhasil dihapus');
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->authorizeOperate();

        $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);
        $deleted = $this->service->bulkDelete($request->ids);
        return $this->successResponse(['deleted' => $deleted], "{$deleted} vendor berhasil dihapus");
    }

    private function authorizeView(): void
    {
        abort_if(!RoleHelper::canViewVendorAp(auth()->user()), 403, 'Tidak memiliki akses ke data vendor');
    }

    private function authorizeOperate(): void
    {
        abort_if(!RoleHelper::canOperateVendorAp(auth()->user()), 403, 'Tidak memiliki akses untuk mengelola data vendor');
    }

    private function authorizePicApVendor(?int $karyawanApId): void
    {
        if (!RoleHelper::isApStaff(auth()->user())) {
            return;
        }

        $user = auth()->user();
        abort_if(
            (int) $user->karyawan_id !== (int) $karyawanApId,
            403,
            'Anda hanya dapat melihat vendor yang ditugaskan kepada Anda'
        );
    }
}
