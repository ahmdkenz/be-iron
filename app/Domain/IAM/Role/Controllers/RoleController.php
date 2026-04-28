<?php

namespace App\Domain\IAM\Role\Controllers;

use App\Domain\IAM\Role\Requests\StoreRoleRequest;
use App\Domain\IAM\Role\Requests\UpdateRoleRequest;
use App\Domain\IAM\Role\Resources\RoleResource;
use App\Domain\IAM\Role\Services\RoleService;
use App\Http\Controllers\Controller;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RoleService $service) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('all')) {
            $roles = $this->service->getAllActive();
            return $this->successResponse(RoleResource::collection($roles));
        }

        $roles = $this->service->getAll($request->only(['search', 'status']));
        return $this->paginatedResponse(
            $roles->through(fn($r) => new RoleResource($r))
        );
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->service->create($request->validated());
        return $this->createdResponse(new RoleResource($role), 'Role berhasil dibuat');
    }

    public function show(int $id): JsonResponse
    {
        $role = $this->service->findOrFail($id);
        return $this->successResponse(new RoleResource($role));
    }

    public function update(UpdateRoleRequest $request, int $id): JsonResponse
    {
        $role = $this->service->findOrFail($id);
        $updated = $this->service->update($role, $request->validated());
        return $this->successResponse(new RoleResource($updated), 'Role berhasil diperbarui');
    }

    public function destroy(int $id): JsonResponse
    {
        $role = $this->service->findOrFail($id);
        $this->service->delete($role);
        return $this->successResponse(null, 'Role berhasil dihapus');
    }
}
