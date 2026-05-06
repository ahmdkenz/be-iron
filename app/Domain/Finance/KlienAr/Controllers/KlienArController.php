<?php

namespace App\Domain\Finance\KlienAr\Controllers;

use App\Domain\Finance\KlienAr\DTO\KlienArDTO;
use App\Domain\Finance\KlienAr\Requests\StoreKlienArRequest;
use App\Domain\Finance\KlienAr\Requests\UpdateKlienArRequest;
use App\Domain\Finance\KlienAr\Resources\KlienArResource;
use App\Domain\Finance\KlienAr\Services\KlienArService;
use App\Http\Controllers\Controller;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KlienArController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly KlienArService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'status', 'perusahaan_id', 'karyawan_ar_id']);
        $this->applyPicArScope($filters);

        $list = $this->service->paginate($filters);

        return $this->paginatedResponse(
            $list->through(fn($k) => new KlienArResource($k))
        );
    }

    public function all(Request $request): JsonResponse
    {
        $filters = $request->only(['perusahaan_id', 'karyawan_ar_id']);
        $this->applyPicArScope($filters);

        $list = $this->service->getAll($filters);
        return $this->successResponse(KlienArResource::collection($list));
    }

    public function previewKode(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'tipe_klien' => ['required', 'in:PT,RESTO,STOKIS,MITRA'],
        ]);

        return $this->successResponse([
            'kode_klien' => $this->service->generateKodeKlien($payload['tipe_klien']),
        ]);
    }

    public function store(StoreKlienArRequest $request): JsonResponse
    {
        abort_if(
            $this->isDirectorOnly(),
            403,
            'Direktur hanya memiliki akses lihat data klien AR'
        );

        $klien = $this->service->create(KlienArDTO::fromRequest($request->validated()));
        return $this->createdResponse(new KlienArResource($klien), 'Klien AR berhasil dibuat');
    }

    public function show(int $id): JsonResponse
    {
        $klien = $this->service->findOrFail($id);
        $this->authorizePicArKlien($klien->karyawan_ar_id);

        return $this->successResponse(new KlienArResource($klien));
    }

    public function update(UpdateKlienArRequest $request, int $id): JsonResponse
    {
        $this->forbidReadOnlyMutation();

        $klien   = $this->service->findOrFail($id);
        $updated = $this->service->update($klien, KlienArDTO::fromRequest($request->validated()));
        return $this->successResponse(new KlienArResource($updated), 'Klien AR berhasil diperbarui');
    }

    public function updateNoWa(Request $request, int $id): JsonResponse
    {
        $data  = $request->validate(['no_wa' => ['nullable', 'string', 'max:20']]);
        $klien = $this->service->findOrFail($id);
        $this->authorizePicArKlien($klien->karyawan_ar_id);

        $klien->update(['no_wa' => $data['no_wa'] ?? null, 'updated_by' => auth()->id()]);

        return $this->successResponse(new KlienArResource($klien->fresh()), 'No. WhatsApp berhasil diperbarui');
    }

    public function destroy(int $id): JsonResponse
    {
        $this->forbidReadOnlyMutation();

        $klien = $this->service->findOrFail($id);
        $this->service->delete($klien);
        return $this->successResponse(null, 'Klien AR berhasil dihapus');
    }

    private function applyPicArScope(array &$filters): void
    {
        if (!$this->isPicArOnly()) {
            return;
        }

        $user = auth()->user();
        $filters['karyawan_ar_id'] = $user->karyawan_id ?: 0;
    }

    private function authorizePicArKlien(?int $karyawanArId): void
    {
        if (!$this->isPicArOnly()) {
            return;
        }

        $user = auth()->user();
        abort_if(
            (int) $user->karyawan_id !== (int) $karyawanArId,
            403,
            'Anda hanya dapat melihat klien AR yang ditugaskan kepada Anda'
        );
    }

    private function forbidReadOnlyMutation(): void
    {
        abort_if(
            $this->isReadOnlyRole(),
            403,
            'Role AR dan Direktur hanya memiliki akses lihat data klien AR'
        );
    }

    private function isReadOnlyRole(): bool
    {
        $user = auth()->user();

        return RoleHelper::isArOnly($user) || RoleHelper::isDirectorOnly($user);
    }

    private function isDirectorOnly(): bool
    {
        return RoleHelper::isDirectorOnly(auth()->user());
    }

    private function isPicArOnly(): bool
    {
        return RoleHelper::isArOnly(auth()->user());
    }
}
