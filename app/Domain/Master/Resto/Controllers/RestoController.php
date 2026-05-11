<?php

namespace App\Domain\Master\Resto\Controllers;

use App\Domain\Master\Resto\DTO\RestoDTO;
use App\Domain\Master\Resto\Requests\StoreRestoRequest;
use App\Domain\Master\Resto\Requests\UpdateRestoRequest;
use App\Domain\Master\Resto\Resources\RestoResource;
use App\Domain\Master\Resto\Services\RestoService;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Investor;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RestoController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RestoService $service) {}

    public function index(Request $request): JsonResponse
    {
        $list = $this->service->paginate($request->only(['search', 'status', 'perusahaan_id', 'karyawan_id']));
        return $this->paginatedResponse($list->through(fn($r) => new RestoResource($r)));
    }

    public function previewKode(Request $request): JsonResponse
    {
        $request->validate([
            'nama_resto' => ['required', 'string', 'max:150'],
        ]);

        $kode = $this->service->generateKodeResto($request->input('nama_resto'));

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

    public function export(Request $request): StreamedResponse
    {
        $filters = $request->only(['search', 'status']);
        $restos  = $this->service->getAllForExport($filters);

        $headers = [
            'Kode Resto', 'Nama Resto', 'Nama Investor', 'Nama Perusahaan',
            'Nama Brand', 'PIC', 'Area', 'Kota', 'Alamat',
            'No. Telp', 'Tanggal Aktif', 'Keterangan', 'Status',
        ];

        return response()->streamDownload(function () use ($restos, $headers) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($handle, $headers);

            foreach ($restos as $r) {
                fputcsv($handle, [
                    $r->kode_resto,
                    $r->nama_resto,
                    $r->investor?->nama_investor,
                    $r->perusahaan?->nama_perusahaan,
                    $r->brand?->nama_brand,
                    $r->pic?->nama_karyawan,
                    $r->area,
                    $r->kota,
                    $r->alamat,
                    $r->no_telp,
                    $r->tgl_aktif?->format('Y-m-d'),
                    $r->keterangan,
                    $r->status ? 1 : 0,
                ]);
            }

            fclose($handle);
        }, 'resto-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function importTemplate(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, [
                'nama_resto', 'nama_investor', 'nama_perusahaan', 'nama_brand',
                'nama_pic', 'area', 'kota', 'alamat', 'no_telp',
                'tgl_aktif', 'keterangan', 'status',
            ]);

            fputcsv($handle, [
                'Resto Contoh', 'Nama Investor', 'Nama Perusahaan', 'Nama Brand',
                'Nama Karyawan PIC', 'Jakarta Pusat', 'Jakarta',
                'Jl. Contoh No. 1', '02112345678', '2026-01-01', '', '1',
            ]);

            fclose($handle);
        }, 'template-resto.csv', [
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

            $namaInvestor  = trim($row[1] ?? '');
            $namaPerusahaan = trim($row[2] ?? '');
            $namaBrand     = trim($row[3] ?? '');
            $namaPic       = trim($row[4] ?? '');

            $investorId  = null;
            $perusahaanId = null;
            $brandId     = null;
            $karyawanId  = null;
            $rowErrors   = [];

            if ($namaInvestor) {
                $investor = Investor::where('nama_investor', $namaInvestor)->first();
                if (!$investor) {
                    $rowErrors[] = "Investor '{$namaInvestor}' tidak ditemukan";
                } else {
                    $investorId = $investor->id;
                }
            }

            if ($namaPerusahaan) {
                $perusahaan = Perusahaan::where('nama_perusahaan', $namaPerusahaan)
                    ->orWhere('nama_singkatan_perusahaan', $namaPerusahaan)
                    ->first();
                if (!$perusahaan) {
                    $rowErrors[] = "Perusahaan '{$namaPerusahaan}' tidak ditemukan";
                } else {
                    $perusahaanId = $perusahaan->id;
                }
            }

            if ($namaBrand) {
                $brand = Brand::where('nama_brand', $namaBrand)->first();
                if (!$brand) {
                    $rowErrors[] = "Brand '{$namaBrand}' tidak ditemukan";
                } else {
                    $brandId = $brand->id;
                }
            }

            if ($namaPic) {
                $karyawan = Karyawan::where('nama_karyawan', $namaPic)->first();
                if (!$karyawan) {
                    $rowErrors[] = "PIC (karyawan) '{$namaPic}' tidak ditemukan";
                } else {
                    $karyawanId = $karyawan->id;
                }
            }

            if (!empty($rowErrors)) {
                $errors[] = ['row' => $lineNumber, 'message' => implode('; ', $rowErrors)];
                continue;
            }

            $data = [
                'nama_resto'    => trim($row[0] ?? ''),
                'investor_id'   => $investorId,
                'perusahaan_id' => $perusahaanId,
                'brand_id'      => $brandId,
                'karyawan_id'   => $karyawanId,
                'area'          => trim($row[5] ?? '') ?: null,
                'kota'          => trim($row[6] ?? '') ?: null,
                'alamat'        => trim($row[7] ?? '') ?: null,
                'no_telp'       => trim($row[8] ?? '') ?: null,
                'tgl_aktif'     => trim($row[9] ?? '') ?: null,
                'keterangan'    => trim($row[10] ?? '') ?: null,
                'status'        => isset($row[11]) && trim($row[11]) !== '' ? (bool)(int)$row[11] : true,
            ];

            $validator = Validator::make($data, [
                'nama_resto'    => ['required', 'string', 'max:150'],
                'investor_id'   => ['nullable', 'integer'],
                'perusahaan_id' => ['nullable', 'integer'],
                'brand_id'      => ['nullable', 'integer'],
                'karyawan_id'   => ['nullable', 'integer'],
                'area'          => ['nullable', 'string', 'max:100'],
                'kota'          => ['nullable', 'string', 'max:100'],
                'alamat'        => ['nullable', 'string'],
                'no_telp'       => ['nullable', 'string', 'max:20'],
                'tgl_aktif'     => ['nullable', 'date'],
                'keterangan'    => ['nullable', 'string'],
                'status'        => ['nullable', 'boolean'],
            ]);

            if ($validator->fails()) {
                $errors[] = [
                    'row'     => $lineNumber,
                    'message' => implode('; ', $validator->errors()->all()),
                ];
                continue;
            }

            $existing = \App\Models\Resto::where('nama_resto', $data['nama_resto'])->latest()->first();

            if ($existing) {
                $this->service->update($existing, RestoDTO::fromRequest($data));
                $updatedCount++;
            } else {
                $this->service->create(RestoDTO::fromRequest($data));
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
            'Role AR dan Direktur hanya memiliki akses lihat data resto'
        );
    }

    private function isReadOnlyRole(): bool
    {
        $user = auth()->user();

        return RoleHelper::isArOnly($user) || RoleHelper::isDirectorOnly($user);
    }
}
