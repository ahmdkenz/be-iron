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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
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

    public function importTemplate(): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $spreadsheet = new Spreadsheet();

        $this->buildDataSheet($spreadsheet->getActiveSheet());
        $this->buildInstructionSheet($spreadsheet->createSheet());

        $spreadsheet->setActiveSheetIndex(0);

        $temp = tempnam(sys_get_temp_dir(), 'tpl_investor_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'template-investor.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    public function import(Request $request): JsonResponse
    {
        $this->forbidReadOnlyMutation();

        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:2048'],
        ]);

        $file      = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $path      = $file->getRealPath();

        $rows = in_array($extension, ['xlsx', 'xls'])
            ? $this->parseXlsx($path)
            : $this->parseCsv($path);

        $insertedCount = 0;
        $updatedCount  = 0;
        $totalData     = 0;
        $errors        = [];
        $lineNumber    = 0;
        $headerSkipped = false;

        foreach ($rows as $row) {
            $lineNumber++;
            $firstCell = trim((string) ($row[0] ?? ''));

            if (str_starts_with($firstCell, '#')) continue;

            if (!$headerSkipped) {
                $headerSkipped = true;
                continue;
            }

            if (str_starts_with($firstCell, '[CONTOH]')) continue;

            if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) continue;

            $totalData++;

            $data = [
                'nama_investor'   => trim((string) ($row[0] ?? '')),
                'ktp'             => $this->importValue($row[1] ?? ''),
                'npwp'            => $this->importValue($row[2] ?? ''),
                'no_hp'           => $this->importValue($row[3] ?? ''),
                'pengelola'       => $this->importValue($row[4] ?? ''),
                'no_hp_pengelola' => $this->importValue($row[5] ?? ''),
                'kode_cabang'     => $this->importValue($row[6] ?? ''),
                'id_cabang'       => $this->importValue($row[7] ?? ''),
                'status'          => isset($row[8]) && trim((string) $row[8]) !== '' ? (bool) (int) $row[8] : true,
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
                'kode_cabang'     => ['nullable', 'string', 'max:50'],
                'id_cabang'       => ['nullable', 'string', 'max:50'],
                'status'          => ['nullable', 'boolean'],
            ]);

            if ($validator->fails()) {
                $errors[] = ['row' => $lineNumber, 'message' => implode('; ', $validator->errors()->all())];
                continue;
            }

            try {
                if ($existing) {
                    $this->service->update($existing, InvestorDTO::fromRequest($data));
                    $updatedCount++;
                } else {
                    $this->service->create(InvestorDTO::fromRequest($data));
                    $insertedCount++;
                }
            } catch (\Exception $e) {
                $errors[] = ['row' => $lineNumber, 'message' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }

        $failed = $totalData - $insertedCount - $updatedCount;

        return $this->successResponse([
            'total'    => $totalData,
            'inserted' => $insertedCount,
            'updated'  => $updatedCount,
            'failed'   => $failed,
            'errors'   => $errors,
        ], "Import selesai. {$insertedCount} ditambahkan, {$updatedCount} diperbarui, {$failed} gagal.");
    }

    private function parseXlsx(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet       = $spreadsheet->getActiveSheet();
        $rows        = [];
        $headerFound = false;

        foreach ($sheet->getRowIterator() as $rowObj) {
            $cellIter = $rowObj->getCellIterator();
            $cellIter->setIterateOnlyExistingCells(false);

            $cells = [];
            foreach ($cellIter as $cell) {
                $cells[] = $this->xlsxCellToString($cell);
            }

            $cells     = array_slice($cells, 0, 9);
            $firstCell = trim($cells[0] ?? '');

            if (!$headerFound) {
                // Locate the actual header row by column name
                if (strtolower($firstCell) === 'nama_investor') {
                    $headerFound = true;
                    $rows[]      = $cells;
                }
                continue;
            }

            $rows[] = $cells;
        }

        return $rows;
    }

    private function parseCsv(string $path): array
    {
        $rows   = [];
        $handle = fopen($path, 'r');

        // Strip UTF-8 BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }

    private function xlsxCellToString(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): string
    {
        $value = $cell->getValue();

        if ($value === null) return '';
        if (is_bool($value)) return $value ? '1' : '0';
        if (is_int($value)) return (string) $value;
        if (is_float($value)) {
            return fmod($value, 1.0) === 0.0
                ? sprintf('%.0f', $value)
                : (string) $value;
        }
        return trim((string) $value);
    }

    private function buildDataSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Data Investor');

        $cols = [
            'A' => ['nama_investor',   28],
            'B' => ['ktp',             20],
            'C' => ['npwp',            22],
            'D' => ['no_hp',           16],
            'E' => ['pengelola',       24],
            'F' => ['no_hp_pengelola', 20],
            'G' => ['kode_cabang',     22],
            'H' => ['id_cabang',       22],
            'I' => ['status',          10],
        ];

        // Row 1 — Title
        $sheet->mergeCells('A1:I1');
        $sheet->setCellValue('A1', 'TEMPLATE IMPORT DATA INVESTOR');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        // Row 2 — Subtitle
        $sheet->mergeCells('A2:I2');
        $sheet->setCellValue('A2', 'Isi data investor di bawah ini. Hapus baris [CONTOH] sebelum import. Lihat sheet "Petunjuk Pengisian" untuk panduan lengkap.');
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF37474F']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE3F2FD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(28);

        // Row 3 — Spacer
        $sheet->getRowDimension(3)->setRowHeight(8);

        // Row 4 — Column headers
        foreach ($cols as $col => [$name, $width]) {
            $sheet->setCellValue("{$col}4", $name);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle('A4:I4')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1976D2']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF0D47A1']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(24);

        // Row 5 — Example row
        $example = [
            'A' => '[CONTOH] Investor Contoh',
            'B' => '3273012345678901',
            'C' => '12.345.678.9-012.000',
            'D' => '08123456789',
            'E' => 'Nama Pengelola',
            'F' => '08198765432',
            'G' => 'CB-001',
            'H' => 'ID-CB-001',
            'I' => '1',
        ];
        foreach ($example as $col => $val) {
            $sheet->getCell("{$col}5")->setValueExplicit($val, DataType::TYPE_STRING);
        }
        $sheet->getStyle('A5:I5')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FFE65100']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF9C4']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFFECB3']]],
        ]);
        $sheet->getRowDimension(5)->setRowHeight(20);

        // Rows 6–55 — Empty data rows with alternating stripe and text-format on numeric columns
        for ($row = 6; $row <= 55; $row++) {
            $bg = $row % 2 === 0 ? 'FFF5F5F5' : 'FFFFFFFF';
            $sheet->getStyle("A{$row}:I{$row}")->applyFromArray([
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFE0E0E0']]],
            ]);
            foreach (['B', 'D', 'F'] as $col) {
                $sheet->getStyle("{$col}{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
            $sheet->getRowDimension($row)->setRowHeight(18);
        }

        $sheet->freezePane('A5');
        $sheet->setAutoFilter('A4:I4');
    }

    private function buildInstructionSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Petunjuk Pengisian');
        $sheet->getColumnDimension('A')->setWidth(22);
        $sheet->getColumnDimension('B')->setWidth(52);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(40);

        $row = 1;

        // Title
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", 'PETUNJUK PENGISIAN — TEMPLATE IMPORT DATA INVESTOR');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(34);
        $row += 2;

        // ─── Section: Cara Pengisian ───────────────────────────────────────────
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", '  CARA PENGISIAN');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1976D2']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++;

        $steps = [
            '1. Jangan ubah nama atau urutan kolom pada baris header (berwarna biru).',
            '2. Hapus baris [CONTOH] sebelum melakukan import data.',
            '3. Isi data mulai dari baris kosong di bawah baris [CONTOH].',
            '4. Kolom opsional dapat dikosongkan atau diisi tanda \'-\' (strip) — sistem akan memperlakukan keduanya sebagai tidak ada nilai.',
            '5. Kolom KTP, No. HP, dan No. HP Pengelola — format sel Excel harus TEXT agar angka panjang tidak berubah ke notasi ilmiah (misal: 3.27E+15).',
            '6. Simpan file sebagai .xlsx atau .csv sebelum diupload ke sistem.',
        ];

        foreach ($steps as $i => $step) {
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->setCellValue("A{$row}", "  {$step}");
            $bg = $i % 2 === 0 ? 'FFFFFFFF' : 'FFF8F9FA';
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font'      => ['size' => 9],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFE0E0E0']]],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(20);
            $row++;
        }
        $row++;

        // ─── Section: Keterangan Kolom ────────────────────────────────────────
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", '  KETERANGAN KOLOM');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1976D2']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++;

        // Table header
        foreach (['A' => 'Kolom', 'B' => 'Keterangan', 'C' => 'Wajib', 'D' => 'Format / Contoh'] as $col => $label) {
            $sheet->setCellValue("{$col}{$row}", $label);
        }
        $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF42A5F5']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1976D2']]],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(20);
        $row++;

        $colInfos = [
            ['nama_investor',   'Nama lengkap investor',             'Ya',       'Teks, maks 150 karakter. Contoh: Ahmad Investor'],
            ['ktp',             'Nomor KTP investor',                'Opsional', '16 digit angka. Contoh: 3273012345678901'],
            ['npwp',            'Nomor NPWP investor',               'Opsional', 'Format: XX.XXX.XXX.X-XXX.XXX. Contoh: 12.345.678.9-012.000'],
            ['no_hp',           'Nomor handphone investor',          'Opsional', 'Awali dengan 0. Contoh: 08123456789'],
            ['pengelola',       'Nama pengelola investasi',          'Opsional', 'Teks, maks 150 karakter'],
            ['no_hp_pengelola', 'Nomor handphone pengelola',         'Opsional', 'Awali dengan 0. Contoh: 08198765432'],
            ['kode_cabang',     'Kode cabang investor',              'Opsional', 'Teks, maks 50 karakter. Contoh: CB-001'],
            ['id_cabang',       'ID cabang investor',                'Opsional', 'Teks, maks 50 karakter. Contoh: ID-CB-001'],
            ['status',          'Status aktif investor',             'Opsional', '1 = Aktif (default), 0 = Tidak Aktif'],
        ];

        foreach ($colInfos as $i => [$colName, $desc, $req, $fmt]) {
            foreach (['A' => $colName, 'B' => $desc, 'C' => $req, 'D' => $fmt] as $cellCol => $val) {
                $sheet->setCellValue("{$cellCol}{$row}", $val);
            }
            $bg = $i % 2 === 0 ? 'FFFFFFFF' : 'FFF5F5F5';
            $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
                'font'      => ['size' => 9],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE0E0E0']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(18);
            $row++;
        }
        $row++;

        // ─── Section: Catatan Penting ─────────────────────────────────────────
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", '  CATATAN PENTING');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFC62828']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++;

        $notes = [
            '• Jika nama_investor SUDAH ADA di sistem, data akan DIPERBARUI (update).',
            '• Jika nama_investor BELUM ADA di sistem, data baru akan DITAMBAHKAN (insert).',
            '• KTP dan NPWP harus unik — tidak boleh duplikat antar investor.',
            '• KTP dan NPWP yang dikosongkan tidak divalidasi keunikannya.',
        ];

        foreach ($notes as $note) {
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->setCellValue("A{$row}", "  {$note}");
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font'      => ['size' => 9, 'color' => ['argb' => 'FFC62828']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFEBEE']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFFCDD2']]],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(18);
            $row++;
        }
    }

    private function importValue(mixed $val): ?string
    {
        $s = trim((string) $val);
        return ($s === '' || $s === '-') ? null : $s;
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
