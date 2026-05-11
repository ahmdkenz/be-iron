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
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function export(Request $request): StreamedResponse
    {
        $filters   = $request->only(['search', 'status']);
        $investors = $this->service->getAllForExport($filters);

        $headers = [
            'Nama Investor', 'KTP', 'NPWP', 'No. HP',
            'Nama Pengelola', 'No. HP Pengelola', 'Alamat', 'Keterangan', 'Status',
        ];

        return response()->streamDownload(function () use ($investors, $headers) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($handle, $headers);

            foreach ($investors as $inv) {
                fputcsv($handle, [
                    $inv->nama_investor,
                    $inv->ktp,
                    $inv->npwp,
                    $inv->no_hp,
                    $inv->pengelola,
                    $inv->no_hp_pengelola,
                    $inv->alamat,
                    $inv->keterangan,
                    $inv->status ? 1 : 0,
                ]);
            }

            fclose($handle);
        }, 'investor-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function importTemplate(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, [
                'nama_investor', 'ktp', 'npwp', 'no_hp',
                'pengelola', 'no_hp_pengelola', 'alamat', 'keterangan', 'status',
            ]);

            fputcsv($handle, [
                'Investor Contoh', '3273012345678901', '12.345.678.9-012.000',
                '08123456789', 'Nama Pengelola', '08198765432',
                'Jl. Contoh No. 1 Jakarta', '', '1',
            ]);

            fclose($handle);
        }, 'template-investor.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $this->forbidReadOnlyMutation();

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $path   = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');

        $insertedCount = 0;
        $updatedCount  = 0;
        $totalData     = 0;
        $errors        = [];
        $lineNumber    = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;

            if ($lineNumber === 1) {
                continue; // skip header
            }

            if (count($row) < 1 || ($row[0] === '' && count(array_filter($row)) === 0)) {
                continue; // skip empty rows
            }

            $totalData++;

            if ($totalData > 500) {
                $errors[] = ['row' => $lineNumber, 'message' => 'Batas maksimum 500 baris per file tercapai.'];
                break;
            }

            $data = [
                'nama_investor'   => trim($row[0] ?? ''),
                'ktp'             => trim($row[1] ?? '') ?: null,
                'npwp'            => trim($row[2] ?? '') ?: null,
                'no_hp'           => trim($row[3] ?? '') ?: null,
                'pengelola'       => trim($row[4] ?? '') ?: null,
                'no_hp_pengelola' => trim($row[5] ?? '') ?: null,
                'alamat'          => trim($row[6] ?? '') ?: null,
                'keterangan'      => trim($row[7] ?? '') ?: null,
                'status'          => isset($row[8]) && trim($row[8]) !== '' ? (bool)(int)$row[8] : true,
            ];

            $existing = \App\Models\Investor::where('nama_investor', $data['nama_investor'])->latest()->first();

            $uniqueKtp  = $existing
                ? Rule::unique('tb_investor', 'ktp')->ignore($existing->id)
                : 'unique:tb_investor,ktp';
            $uniqueNpwp = $existing
                ? Rule::unique('tb_investor', 'npwp')->ignore($existing->id)
                : 'unique:tb_investor,npwp';

            $validator = Validator::make($data, [
                'nama_investor'   => ['required', 'string', 'max:150'],
                'ktp'             => ['nullable', 'string', 'max:20', $uniqueKtp],
                'npwp'            => ['nullable', 'string', 'max:20', $uniqueNpwp],
                'no_hp'           => ['nullable', 'string', 'max:20'],
                'pengelola'       => ['nullable', 'string', 'max:150'],
                'no_hp_pengelola' => ['nullable', 'string', 'max:20'],
                'alamat'          => ['nullable', 'string'],
                'keterangan'      => ['nullable', 'string'],
                'status'          => ['nullable', 'boolean'],
            ]);

            if ($validator->fails()) {
                $errors[] = [
                    'row'     => $lineNumber,
                    'message' => implode('; ', $validator->errors()->all()),
                ];
                continue;
            }

            if ($existing) {
                $this->service->update($existing, InvestorDTO::fromRequest($data));
                $updatedCount++;
            } else {
                $this->service->create(InvestorDTO::fromRequest($data));
                $insertedCount++;
            }
        }

        fclose($handle);

        $failed = $totalData - $insertedCount - $updatedCount;

        return $this->successResponse([
            'total'    => $totalData,
            'inserted' => $insertedCount,
            'updated'  => $updatedCount,
            'failed'   => $failed,
            'errors'   => $errors,
        ], "Import selesai. {$insertedCount} ditambahkan, {$updatedCount} diperbarui, {$failed} gagal.");
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
