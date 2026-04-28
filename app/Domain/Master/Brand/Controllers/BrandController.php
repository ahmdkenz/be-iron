<?php

namespace App\Domain\Master\Brand\Controllers;

use App\Domain\Master\Brand\DTO\BrandDTO;
use App\Domain\Master\Brand\Requests\StoreBrandRequest;
use App\Domain\Master\Brand\Requests\UpdateBrandRequest;
use App\Domain\Master\Brand\Resources\BrandResource;
use App\Domain\Master\Brand\Services\BrandService;
use App\Http\Controllers\Controller;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BrandService $service) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('all')) {
            $list = $this->service->getAll([], all: true);
            return $this->successResponse($list->map(fn($b) => new BrandResource($b)));
        }

        $list = $this->service->getAll($request->only(['search', 'status']));
        return $this->paginatedResponse($list->through(fn($b) => new BrandResource($b)));
    }

    public function store(StoreBrandRequest $request): JsonResponse
    {
        $brand = $this->service->create(BrandDTO::fromRequest($request->validated()));
        return $this->createdResponse(new BrandResource($brand), 'Brand berhasil dibuat');
    }

    public function show(int $id): JsonResponse
    {
        $brand = $this->service->findOrFail($id);
        return $this->successResponse(new BrandResource($brand));
    }

    public function update(UpdateBrandRequest $request, int $id): JsonResponse
    {
        $brand   = $this->service->findOrFail($id);
        $updated = $this->service->update($brand, BrandDTO::fromRequest($request->validated()));
        return $this->successResponse(new BrandResource($updated), 'Brand berhasil diperbarui');
    }

    public function destroy(int $id): JsonResponse
    {
        $brand = $this->service->findOrFail($id);
        $this->service->delete($brand);
        return $this->successResponse(null, 'Brand berhasil dihapus');
    }
}
