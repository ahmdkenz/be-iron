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

    public function importTemplate(): BinaryFileResponse
    {
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

            if ($totalData > 500) {
                $errors[] = ['row' => $lineNumber, 'message' => 'Batas maksimum 500 baris per file tercapai.'];
                break;
            }

            $data = [
                'nama_investor'   => trim((string) ($row[0] ?? '')),
                'ktp'             => $this->importValue($row[1] ?? ''),
                'npwp'            => $this->importValue($row[2] ?? ''),
                'no_hp'           => $this->importValue($row[3] ?? ''),
                'pengelola'       => $this->importValue($row[4] ?? ''),
                'no_hp_pengelola' => $this->importValue($row[5] ?? ''),
                'alamat'          => $this->importValue($row[6] ?? ''),
                'keterangan'      => $this->importValue($row[7] ?? ''),
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
                'alamat'          => ['nullable', 'string'],
                'keterangan'      => ['nullable', 'string'],
                'status'          => ['nullable', 'boolean'],
            ]);

            if ($validator->fails()) {
                $errors[] = ['row' => $lineNumber, 'message' => implode('; ', $validator->errors()->all())];
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
            'G' => ['alamat',          35],
            'H' => ['keterangan',      24],
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
            'G' => 'Jl. Contoh No. 1 Jakarta',
            'H' => 'Catatan opsional',
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
            '5. Maksimal 500 baris data per file.',
            '6. Kolom KTP, No. HP, dan No. HP Pengelola — format sel Excel harus TEXT agar angka panjang tidak berubah ke notasi ilmiah (misal: 3.27E+15).',
            '7. Simpan file sebagai .xlsx atau .csv sebelum diupload ke sistem.',
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
            ['alamat',          'Alamat lengkap investor',           'Opsional', 'Teks bebas. Contoh: Jl. Contoh No. 1, Jakarta'],
            ['keterangan',      'Catatan atau keterangan tambahan',  'Opsional', 'Teks bebas'],
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
