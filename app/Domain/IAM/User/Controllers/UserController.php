<?php

namespace App\Domain\IAM\User\Controllers;

use App\Domain\IAM\User\DTO\UserDTO;
use App\Domain\IAM\User\Requests\StoreUserRequest;
use App\Domain\IAM\User\Requests\UpdateUserRequest;
use App\Domain\IAM\User\Resources\UserResource;
use App\Domain\IAM\User\Services\UserService;
use App\Http\Controllers\Controller;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly UserService $service) {}

    public function index(Request $request): JsonResponse
    {
        $users = $this->service->getAll($request->only(['search', 'status']));

        return $this->paginatedResponse(
            $users->through(fn($u) => new UserResource($u))
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->service->create(UserDTO::fromRequest($request->validated()));
        return $this->createdResponse(new UserResource($user), 'User berhasil dibuat');
    }

    public function show(int $id): JsonResponse
    {
        $user = $this->service->findOrFail($id);
        return $this->successResponse(new UserResource($user));
    }

    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $user = $this->service->findOrFail($id);
        $updated = $this->service->update($user, UserDTO::fromRequest($request->validated()));
        return $this->successResponse(new UserResource($updated), 'User berhasil diperbarui');
    }

    public function destroy(int $id): JsonResponse
    {
        $user = $this->service->findOrFail($id);
        $this->service->delete($user);
        return $this->successResponse(null, 'User berhasil dihapus');
    }
}
