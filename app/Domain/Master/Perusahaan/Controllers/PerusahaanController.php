<?php

namespace App\Domain\Master\Perusahaan\Controllers;

use App\Domain\Master\Perusahaan\DTO\PerusahaanDTO;
use App\Domain\Master\Perusahaan\Requests\StorePerusahaanRequest;
use App\Domain\Master\Perusahaan\Requests\UpdatePerusahaanRequest;
use App\Domain\Master\Perusahaan\Resources\PerusahaanResource;
use App\Domain\Master\Perusahaan\Services\PerusahaanService;
use App\Http\Controllers\Controller;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PerusahaanController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PerusahaanService $service) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('all')) {
            $list = $this->service->getAll($request->only(['search', 'status']), all: true);
            return $this->successResponse($list->map(fn($p) => new PerusahaanResource($p)));
        }

        $list = $this->service->getAll($request->only(['search', 'status']));

        return $this->paginatedResponse(
            $list->through(fn($p) => new PerusahaanResource($p))
        );
    }

    public function store(StorePerusahaanRequest $request): JsonResponse
    {
        $perusahaan = $this->service->create(PerusahaanDTO::fromRequest($request->validated()));
        return $this->createdResponse(new PerusahaanResource($perusahaan), 'Perusahaan berhasil dibuat');
    }

    public function show(int $id): JsonResponse
    {
        $perusahaan = $this->service->findOrFail($id);
        return $this->successResponse(new PerusahaanResource($perusahaan));
    }

    public function update(UpdatePerusahaanRequest $request, int $id): JsonResponse
    {
        $perusahaan = $this->service->findOrFail($id);
        $updated = $this->service->update($perusahaan, PerusahaanDTO::fromRequest($request->validated()));
        return $this->successResponse(new PerusahaanResource($updated), 'Perusahaan berhasil diperbarui');
    }

    public function destroy(int $id): JsonResponse
    {
        $perusahaan = $this->service->findOrFail($id);
        $this->service->delete($perusahaan);
        return $this->successResponse(null, 'Perusahaan berhasil dihapus');
    }
}
