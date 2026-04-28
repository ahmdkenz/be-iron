<?php

namespace App\Domain\Master\Barang\Controllers;

use App\Domain\Master\Barang\DTO\BarangDTO;
use App\Domain\Master\Barang\Requests\StoreBarangRequest;
use App\Domain\Master\Barang\Requests\UpdateBarangRequest;
use App\Domain\Master\Barang\Resources\BarangResource;
use App\Domain\Master\Barang\Services\BarangService;
use App\Http\Controllers\Controller;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BarangController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BarangService $service) {}

    public function index(Request $request): JsonResponse
    {
        $list = $this->service->paginate($request->only(['search', 'status', 'perusahaan_id', 'brand_id']));
        return $this->paginatedResponse($list->through(fn($b) => new BarangResource($b)));
    }

    public function store(StoreBarangRequest $request): JsonResponse
    {
        $this->forbidReadOnlyMutation();

        $barang = $this->service->create(BarangDTO::fromRequest($request->validated()));
        return $this->createdResponse(new BarangResource($barang), 'Barang berhasil dibuat');
    }

    public function show(int $id): JsonResponse
    {
        $barang = $this->service->findOrFail($id);
        return $this->successResponse(new BarangResource($barang));
    }

    public function update(UpdateBarangRequest $request, int $id): JsonResponse
    {
        $this->forbidReadOnlyMutation();

        $barang  = $this->service->findOrFail($id);
        $updated = $this->service->update($barang, BarangDTO::fromRequest($request->validated()));
        return $this->successResponse(new BarangResource($updated), 'Barang berhasil diperbarui');
    }

    public function destroy(int $id): JsonResponse
    {
        $this->forbidReadOnlyMutation();

        $barang = $this->service->findOrFail($id);
        $this->service->delete($barang);
        return $this->successResponse(null, 'Barang berhasil dihapus');
    }

    private function forbidReadOnlyMutation(): void
    {
        abort_if(
            $this->isReadOnlyRole(),
            403,
            'Role AR dan Direktur hanya memiliki akses lihat data barang'
        );
    }

    private function isReadOnlyRole(): bool
    {
        $user = auth()->user();

        return RoleHelper::isArOnly($user) || RoleHelper::isDirectorOnly($user);
    }
}
