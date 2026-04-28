<?php

namespace App\Domain\Master\Investor\Controllers;

use App\Domain\Master\Investor\DTO\InvestorDTO;
use App\Domain\Master\Investor\Requests\StoreInvestorRequest;
use App\Domain\Master\Investor\Requests\UpdateInvestorRequest;
use App\Domain\Master\Investor\Resources\InvestorResource;
use App\Domain\Master\Investor\Services\InvestorService;
use App\Http\Controllers\Controller;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvestorController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly InvestorService $service) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('all')) {
            $list = $this->service->getAll(all: true);
            return $this->successResponse($list->map(fn($i) => new InvestorResource($i)));
        }

        $list = $this->service->getAll($request->only(['search', 'status']));
        return $this->paginatedResponse($list->through(fn($i) => new InvestorResource($i)));
    }

    public function store(StoreInvestorRequest $request): JsonResponse
    {
        $this->forbidReadOnlyMutation();

        $investor = $this->service->create(InvestorDTO::fromRequest($request->validated()));
        return $this->createdResponse(new InvestorResource($investor), 'Investor berhasil dibuat');
    }

    public function show(int $id): JsonResponse
    {
        $investor = $this->service->findOrFail($id);
        return $this->successResponse(new InvestorResource($investor));
    }

    public function update(UpdateInvestorRequest $request, int $id): JsonResponse
    {
        $this->forbidReadOnlyMutation();

        $investor = $this->service->findOrFail($id);
        $updated  = $this->service->update($investor, InvestorDTO::fromRequest($request->validated()));
        return $this->successResponse(new InvestorResource($updated), 'Investor berhasil diperbarui');
    }

    public function destroy(int $id): JsonResponse
    {
        $this->forbidReadOnlyMutation();

        $investor = $this->service->findOrFail($id);
        $this->service->delete($investor);
        return $this->successResponse(null, 'Investor berhasil dihapus');
    }

    private function forbidReadOnlyMutation(): void
    {
        abort_if(
            $this->isReadOnlyRole(),
            403,
            'Role AR dan Direktur hanya memiliki akses lihat data investor'
        );
    }

    private function isReadOnlyRole(): bool
    {
        $user = auth()->user();

        return RoleHelper::isArOnly($user) || RoleHelper::isDirectorOnly($user);
    }
}
