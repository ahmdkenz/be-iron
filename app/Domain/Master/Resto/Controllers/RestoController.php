<?php

namespace App\Domain\Master\Resto\Controllers;

use App\Domain\Master\Resto\DTO\RestoDTO;
use App\Domain\Master\Resto\Requests\StoreRestoRequest;
use App\Domain\Master\Resto\Requests\UpdateRestoRequest;
use App\Domain\Master\Resto\Resources\RestoResource;
use App\Domain\Master\Resto\Services\RestoService;
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
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RestoController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RestoService $service) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = $request->boolean('all') ? 0 : (int) $request->input('per_page', 15);
        $list = $this->service->paginate($request->only(['search', 'status', 'perusahaan_id', 'karyawan_id']), $perPage);
        return $this->paginatedResponse($list->through(fn($r) => new RestoResource($r)));
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
            "{$deleted} resto berhasil dihapus"
        );
    }

    public function export(Request $request): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $filters = $request->only(['search', 'status']);
        $restos  = $this->service->getAllForExport($filters);

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Resto');

        $lastCol = 'P';
        $columns = [
            'A' => ['Kode Resto',       14],
            'B' => ['Nama Resto',        28],
            'C' => ['Nama Investor',     24],
            'D' => ['Nama Entitas',      22],
            'E' => ['Nama Brand',        18],
            'F' => ['PIC',               20],
            'G' => ['Supervisor',        24],
            'H' => ['No. HP SPV',        18],
            'I' => ['STOKIS',            20],
            'J' => ['Area',              18],
            'K' => ['Kota',              16],
            'L' => ['Alamat',            35],
            'M' => ['No. Telp',          16],
            'N' => ['Tanggal Aktif',     16],
            'O' => ['Keterangan',        25],
            'P' => ['Status',            12],
        ];

        // Row 1 — Title
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'DATA RESTO');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        // Row 2 — Subtitle
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', 'Diekspor pada: ' . now()->format('d-m-Y H:i:s') . ' | Total data: ' . count($restos) . ' resto');
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF1B5E20']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF1F8E9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(20);

        // Row 3 — Spacer
        $sheet->getRowDimension(3)->setRowHeight(6);

        // Row 4 — Column headers
        foreach ($columns as $col => [$label, $width]) {
            $sheet->setCellValue("{$col}4", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E7D32']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1B5E20']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(24);

        // Rows 5+ — Data
        $dataStartRow = 5;
        $rowNum       = $dataStartRow;

        foreach ($restos as $r) {
            $bg = ($rowNum % 2 === 0) ? 'FFF1F8E9' : 'FFFFFFFF';

            $tglAktif = $r->tgl_aktif?->format('d-m-Y') ?? '-';
            $status   = $r->status ? 'Aktif' : 'Tidak Aktif';

            $rowData = [
                'A' => $r->kode_resto                  ?? '-',
                'B' => $r->nama_resto                  ?? '-',
                'C' => $r->investor?->nama_investor     ?? '-',
                'D' => $r->perusahaan?->nama_perusahaan ?? '-',
                'E' => $r->brand?->nama_brand           ?? '-',
                'F' => $r->pic?->nama_karyawan          ?? '-',
                'G' => $r->supervisor                   ?? '-',
                'H' => $r->no_hp_supervisor             ?? '-',
                'I' => $r->stokis                       ?? '-',
                'J' => $r->area                         ?? '-',
                'K' => $r->kota                         ?? '-',
                'L' => $r->alamat                       ?? '-',
                'M' => $r->no_telp                      ?? '-',
                'N' => $tglAktif,
                'O' => $r->keterangan                   ?? '-',
                'P' => $status,
            ];

            // Force text format for Kode Resto, No. Telp, and Tanggal Aktif columns
            foreach (['A', 'H', 'M', 'N'] as $textCol) {
                $sheet->getStyle("{$textCol}{$rowNum}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }

            foreach ($rowData as $col => $val) {
                $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val, DataType::TYPE_STRING);
            }

            $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFBDBDBD']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);

            // Color the status cell
            $statusColor = $r->status ? ['argb' => 'FF1B5E20'] : ['argb' => 'FFB71C1C'];
            $sheet->getStyle("P{$rowNum}")->applyFromArray([
                'font'      => ['bold' => true, 'color' => $statusColor],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $sheet->getRowDimension($rowNum)->setRowHeight(18);
            $rowNum++;
        }

        // Outer border around data area
        if ($rowNum > $dataStartRow) {
            $sheet->getStyle("A4:{$lastCol}" . ($rowNum - 1))->applyFromArray([
                'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF2E7D32']]],
            ]);
        }

        $sheet->freezePane('A5');

        $temp = tempnam(sys_get_temp_dir(), 'exp_resto_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'resto-' . now()->format('Ymd-His') . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
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

        return RoleHelper::isArOnly($user);
    }
}
