<?php

namespace App\Domain\Master\Karyawan\Controllers;

use App\Domain\Master\Karyawan\DTO\KaryawanDTO;
use App\Domain\Master\Karyawan\Requests\StoreKaryawanRequest;
use App\Domain\Master\Karyawan\Requests\UpdateKaryawanRequest;
use App\Domain\Master\Karyawan\Resources\KaryawanResource;
use App\Domain\Master\Karyawan\Services\KaryawanService;
use App\Http\Controllers\Controller;
use App\Models\Karyawan;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KaryawanController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly KaryawanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = $request->boolean('all') ? 0 : (int) $request->input('per_page', 15);
        $list = $this->service->getAll($request->only(['search', 'status', 'role']), $perPage);

        return $this->paginatedResponse(
            $list->through(fn($k) => new KaryawanResource($k))
        );
    }

    public function store(StoreKaryawanRequest $request): JsonResponse
    {
        $karyawan = $this->service->create(KaryawanDTO::fromRequest($request->validated()));
        return $this->createdResponse(new KaryawanResource($karyawan), 'Karyawan berhasil dibuat');
    }

    public function show(int $id): JsonResponse
    {
        $karyawan = $this->service->findOrFail($id);
        return $this->successResponse(new KaryawanResource($karyawan));
    }

    public function update(UpdateKaryawanRequest $request, int $id): JsonResponse
    {
        $karyawan = $this->service->findOrFail($id);
        $updated = $this->service->update($karyawan, KaryawanDTO::fromRequest($request->validated()));
        return $this->successResponse(new KaryawanResource($updated), 'Karyawan berhasil diperbarui');
    }

    public function destroy(int $id): JsonResponse
    {
        $karyawan = $this->service->findOrFail($id);
        $this->service->delete($karyawan);
        return $this->successResponse(null, 'Karyawan berhasil dihapus');
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = $this->service->bulkDelete($request->ids);

        return $this->successResponse(
            ['deleted' => $deleted],
            "{$deleted} karyawan berhasil dihapus"
        );
    }

    public function search(Request $request): JsonResponse
    {
        $nik = $request->get('nik', '');
        $excludeUserId = $request->get('exclude_user_id');

        $results = Karyawan::where('status', true)
            ->when($nik !== '', fn($q) => $q->where('nik', 'like', "%{$nik}%"))
            ->with('user:id,karyawan_id')
            ->select('id', 'nik', 'nama_karyawan')
            ->orderBy('nik')
            ->get()
            ->map(fn($k) => [
                'id'            => $k->id,
                'nik'           => $k->nik,
                'nama_karyawan' => $k->nama_karyawan,
                'is_used'       => $k->user !== null && (int) $k->user->id !== (int) $excludeUserId,
            ]);

        return $this->successResponse($results);
    }
}
