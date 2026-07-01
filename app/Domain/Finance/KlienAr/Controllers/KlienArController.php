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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class KlienArController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly KlienArService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'status', 'perusahaan_id', 'karyawan_ar_id', 'segment']);
        $this->applyPicArScope($filters);

        if ($request->boolean('all')) {
            $list = $this->service->getAll($filters);

            return $this->successResponse(KlienArResource::collection($list));
        }

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
            'tipe_klien' => ['nullable', 'in:PT,RESTO'],
        ]);

        return $this->successResponse([
            'kode_klien' => $this->service->generateKodeKlien($payload['tipe_klien'] ?? 'RESTO'),
        ]);
    }

    public function store(StoreKlienArRequest $request): JsonResponse
    {
        $klien = $this->service->create(KlienArDTO::fromRequest($request->validated()));
        return $this->createdResponse(new KlienArResource($klien), 'Client berhasil dibuat');
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
        return $this->successResponse(new KlienArResource($updated), 'Client berhasil diperbarui');
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
        return $this->successResponse(null, 'Client berhasil dihapus');
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->forbidReadOnlyMutation();

        $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);
        $deleted = $this->service->bulkDelete($request->ids);
        return $this->successResponse(['deleted' => $deleted], "{$deleted} client berhasil dihapus");
    }

    public function export(Request $request): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $filters = $request->only(['search', 'status', 'segment', 'karyawan_ar_id']);
        $this->applyPicArScope($filters);

        $klientArs = $this->service->getAllForExport($filters);

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Client');

        $cols = [
            'A' => ['Kode Klien',               18],
            'B' => ['Nama Investor (Billing)',   28],
            'C' => ['Tipe Klien',                14],
            'D' => ['Segment',                   12],
            'E' => ['Nama Resto',                28],
            'F' => ['Nama Investor',             28],
            'G' => ['No. NPWP',                  22],
            'H' => ['No. WhatsApp',              18],
            'I' => ['PIC AR',                    24],
            'J' => ['Status',                    14],
        ];
        $lastCol = 'J';

        // Row 1 — Title
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'DATA Client');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        // Row 2 — Subtitle
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', 'Diekspor pada: ' . now()->format('d-m-Y H:i') . '   |   Total data: ' . $klientArs->count() . ' klien');
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF33691E']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF1F8E9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(22);

        // Row 3 — Spacer
        $sheet->getRowDimension(3)->setRowHeight(6);

        // Row 4 — Column headers
        foreach ($cols as $col => [$label, $width]) {
            $sheet->setCellValue("{$col}4", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E7D32']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1B5E20']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(22);

        // Rows 5+ — Data
        $rowNum = 5;
        foreach ($klientArs as $klien) {
            $bg      = $rowNum % 2 === 0 ? 'FFF1F8E9' : 'FFFFFFFF';
            $isB2C   = $klien->tipe_klien === 'RESTO';
            $segment = $isB2C ? 'B2C' : 'B2B';

            if ($isB2C) {
                $effectiveNpwp = $klien->resto?->investor?->npwp ?? $klien->no_npwp ?? '-';
            } else {
                $effectiveNpwp = $klien->perusahaan?->no_npwp ?? $klien->no_npwp ?? '-';
            }

            $rowData = [
                'A' => [$klien->kode_klien,                                    DataType::TYPE_STRING],
                'B' => [$klien->nama_klien,                                    DataType::TYPE_STRING],
                'C' => [$klien->tipe_klien,                                    DataType::TYPE_STRING],
                'D' => [$segment,                                              DataType::TYPE_STRING],
                'E' => [$klien->resto?->nama_resto ?? '-',                     DataType::TYPE_STRING],
                'F' => [$klien->resto?->investor?->nama_investor ?? '-',       DataType::TYPE_STRING],
                'G' => [$effectiveNpwp,                                        DataType::TYPE_STRING],
                'H' => [$klien->no_wa ?? '-',                                 DataType::TYPE_STRING],
                'I' => [$klien->karyawanAr?->nama_karyawan ?? '-',            DataType::TYPE_STRING],
                'J' => [$klien->status ? 'Aktif' : 'Tidak Aktif',            DataType::TYPE_STRING],
            ];

            foreach ($rowData as $col => [$val, $type]) {
                $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val, $type);
            }

            $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => false],
            ]);

            $statusColor = $klien->status ? 'FF2E7D32' : 'FFAF2018';
            $sheet->getStyle("J{$rowNum}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($statusColor));
            $sheet->getStyle("J{$rowNum}")->getFont()->setBold(true);

            $sheet->getRowDimension($rowNum)->setRowHeight(18);
            $rowNum++;
        }

        if ($rowNum > 5) {
            $sheet->getStyle("A4:{$lastCol}" . ($rowNum - 1))->applyFromArray([
                'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF2E7D32']]],
            ]);
        }

        $sheet->freezePane('A5');

        $temp = tempnam(sys_get_temp_dir(), 'export_klienar_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'klien-ar-' . now()->format('Ymd-His') . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
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
            'Anda hanya dapat melihat Client yang ditugaskan kepada Anda'
        );
    }

    private function forbidReadOnlyMutation(): void
    {
        abort_if(
            $this->isReadOnlyRole(),
            403,
            'Role AR dan Direktur hanya memiliki akses lihat data Client'
        );
    }

    private function isReadOnlyRole(): bool
    {
        $user = auth()->user();

        return RoleHelper::isArOnly($user);
    }

    private function isPicArOnly(): bool
    {
        return RoleHelper::isArOnly(auth()->user());
    }
}
