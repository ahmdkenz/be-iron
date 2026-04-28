<?php

namespace App\Domain\Master\Resto\Controllers;

use App\Domain\Master\Resto\DTO\RestoDTO;
use App\Domain\Master\Resto\Requests\StoreRestoRequest;
use App\Domain\Master\Resto\Requests\UpdateRestoRequest;
use App\Domain\Master\Resto\Resources\RestoResource;
use App\Domain\Master\Resto\Services\RestoService;
use App\Http\Controllers\Controller;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RestoController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RestoService $service) {}

    public function index(Request $request): JsonResponse
    {
        $list = $this->service->paginate($request->only(['search', 'status', 'perusahaan_id']));
        return $this->paginatedResponse($list->through(fn($r) => new RestoResource($r)));
    }

    public function previewKode(Request $request): JsonResponse
    {
        $this->forbidReadOnlyMutation();

        $request->validate([
            'perusahaan_id' => ['required', 'integer', 'exists:tb_perusahaan,id'],
            'brand_id'      => ['required', 'integer', 'exists:tb_brand,id'],
        ]);

        $kode = $this->service->generateKodeResto(
            (int) $request->input('perusahaan_id'),
            (int) $request->input('brand_id')
        );

        return $this->successResponse(['kode' => $kode]);
    }

    public function store(StoreRestoRequest $request): JsonResponse
    {
        $this->forbidReadOnlyMutation();

        $resto = $this->service->create(RestoDTO::fromRequest($request->validated()));
        return $this->createdResponse(new RestoResource($resto), 'Resto berhasil dibuat');
    }

    public function show(int $id): JsonResponse
    {
        $resto = $this->service->findOrFail($id);
        return $this->successResponse(new RestoResource($resto));
    }

    public function update(UpdateRestoRequest $request, int $id): JsonResponse
    {
        $this->forbidReadOnlyMutation();

        $resto   = $this->service->findOrFail($id);
        $updated = $this->service->update($resto, RestoDTO::fromRequest($request->validated()));
        return $this->successResponse(new RestoResource($updated), 'Resto berhasil diperbarui');
    }

    public function destroy(int $id): JsonResponse
    {
        $this->forbidReadOnlyMutation();

        $resto = $this->service->findOrFail($id);
        $this->service->delete($resto);
        return $this->successResponse(null, 'Resto berhasil dihapus');
    }

    private function forbidReadOnlyMutation(): void
    {
        abort_if(
            $this->isReadOnlyRole(),
            403,
            'Role AR dan Direktur hanya memiliki akses lihat data resto'
        );
    }

    private function isReadOnlyRole(): bool
    {
        $user = auth()->user();

        return RoleHelper::isArOnly($user) || RoleHelper::isDirectorOnly($user);
    }
}
