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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

        $perPage = (int) $request->input('per_page', 15);
        $list = $this->service->getAll($request->only(['search', 'status']), $perPage);
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

    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->forbidReadOnlyMutation();

        $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = $this->service->bulkDelete($request->ids);

        return $this->successResponse(
            ['deleted' => $deleted],
            "{$deleted} investor berhasil dihapus"
        );
    }

    public function export(Request $request): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $filters   = $request->only(['search', 'status']);
        $investors = $this->service->getAllForExport($filters);

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Investor');

        $cols = [
            'A' => ['Nama Investor',       35],
            'B' => ['No. KTP',             22],
            'C' => ['NPWP',                24],
            'D' => ['No. HP',              18],
            'E' => ['Nama Pengelola',       28],
            'F' => ['No. HP Pengelola',     20],
            'G' => ['Kode Cabang',         24],
            'H' => ['ID Cabang',           24],
            'I' => ['Status',              14],
        ];
        $lastCol = 'I';

        // Row 1 — Title
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'DATA INVESTOR');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        // Row 2 — Subtitle (date + total)
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', 'Diekspor pada: ' . now()->format('d-m-Y H:i') . '   |   Total data: ' . $investors->count() . ' investor');
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
        foreach ($investors as $inv) {
            $bg = $rowNum % 2 === 0 ? 'FFF1F8E9' : 'FFFFFFFF';

            $rowData = [
                'A' => [$inv->nama_investor,    DataType::TYPE_STRING],
                'B' => [$inv->ktp ?? '-',        DataType::TYPE_STRING],
                'C' => [$inv->npwp ?? '-',       DataType::TYPE_STRING],
                'D' => [$inv->no_hp ?? '-',      DataType::TYPE_STRING],
                'E' => [$inv->pengelola ?? '-',  DataType::TYPE_STRING],
                'F' => [$inv->no_hp_pengelola ?? '-', DataType::TYPE_STRING],
                'G' => [$inv->kode_cabang ?? '-', DataType::TYPE_STRING],
                'H' => [$inv->id_cabang ?? '-',  DataType::TYPE_STRING],
                'I' => [$inv->status ? 'Aktif' : 'Tidak Aktif', DataType::TYPE_STRING],
            ];

            foreach ($rowData as $col => [$val, $type]) {
                $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val, $type);
            }

            $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => false],
            ]);

            // Status column: color based on value
            $statusColor = $inv->status ? 'FF2E7D32' : 'FFAF2018';
            $sheet->getStyle("I{$rowNum}")->getFont()->setColor(
                new \PhpOffice\PhpSpreadsheet\Style\Color($statusColor)
            );
            $sheet->getStyle("I{$rowNum}")->getFont()->setBold(true);

            $sheet->getRowDimension($rowNum)->setRowHeight(18);
            $rowNum++;
        }

        // Outer border around data area
        if ($rowNum > 5) {
            $sheet->getStyle("A4:{$lastCol}" . ($rowNum - 1))->applyFromArray([
                'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF2E7D32']]],
            ]);
        }

        $sheet->freezePane('A5');

        $temp = tempnam(sys_get_temp_dir(), 'export_investor_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'investor-' . now()->format('Ymd-His') . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
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

        return RoleHelper::isArOnly($user);
    }
}
