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

    public function importTemplate(): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet();

        $this->buildDataSheet($spreadsheet->getActiveSheet());
        $this->buildInstructionSheet($spreadsheet->createSheet());

        $spreadsheet->setActiveSheetIndex(0);

        $temp = tempnam(sys_get_temp_dir(), 'tpl_resto_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'template-resto.xlsx', [
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

            $namaInvestor   = $this->importValue($row[1] ?? '') ?? '';
            $namaPerusahaan = $this->importValue($row[2] ?? '') ?? '';
            $namaBrand      = $this->importValue($row[3] ?? '') ?? '';
            $namaPic        = $this->importValue($row[4] ?? '') ?? '';

            $investorId   = null;
            $perusahaanId = null;
            $brandId      = null;
            $karyawanId   = null;
            $rowErrors    = [];

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
                'nama_resto'    => $firstCell,
                'investor_id'   => $investorId,
                'perusahaan_id' => $perusahaanId,
                'brand_id'      => $brandId,
                'karyawan_id'   => $karyawanId,
                'area'          => $this->importValue($row[5] ?? ''),
                'kota'          => $this->importValue($row[6] ?? ''),
                'alamat'        => $this->importValue($row[7] ?? ''),
                'no_telp'       => $this->importValue($row[8] ?? ''),
                'tgl_aktif'     => $this->importDate($row[9] ?? ''),
                'keterangan'    => $this->importValue($row[10] ?? ''),
                'status'        => isset($row[11]) && trim((string) $row[11]) !== '' ? (bool) (int) $row[11] : true,
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
                $errors[] = ['row' => $lineNumber, 'message' => implode('; ', $validator->errors()->all())];
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

            $cells     = array_slice($cells, 0, 12);
            $firstCell = trim($cells[0] ?? '');

            if (!$headerFound) {
                if (strtolower($firstCell) === 'nama_resto') {
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
        $sheet->setTitle('Data Resto');

        $cols = [
            'A' => ['nama_resto',       28],
            'B' => ['nama_investor',    26],
            'C' => ['nama_perusahaan',  26],
            'D' => ['nama_brand',       20],
            'E' => ['nama_pic',         26],
            'F' => ['area',             18],
            'G' => ['kota',             18],
            'H' => ['alamat',           35],
            'I' => ['no_telp',          18],
            'J' => ['tgl_aktif',        16],
            'K' => ['keterangan',       25],
            'L' => ['status',           10],
        ];

        $lastCol = 'L';

        // Row 1 — Title
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'TEMPLATE IMPORT DATA RESTO');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        // Row 2 — Subtitle
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', 'Isi data resto di bawah ini. Hapus baris [CONTOH] sebelum import. Lihat sheet "Petunjuk Pengisian" untuk panduan lengkap.');
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
        $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1976D2']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF0D47A1']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(24);

        // Row 5 — Example row
        $example = [
            'A' => '[CONTOH] Resto Contoh',
            'B' => 'Nama Investor',
            'C' => 'Nama Perusahaan',
            'D' => 'Nama Brand',
            'E' => 'Nama Karyawan PIC',
            'F' => 'Jakarta Pusat',
            'G' => 'Jakarta',
            'H' => 'Jl. Contoh No. 1',
            'I' => '02112345678',
            'J' => '01-01-2026',
            'K' => 'Catatan opsional',
            'L' => '1',
        ];
        // Set text format for numeric-sensitive columns BEFORE writing values to prevent Excel auto-conversion
        $sheet->getStyle('I5')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle('J5')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        foreach ($example as $col => $val) {
            $sheet->getCell("{$col}5")->setValueExplicit($val, DataType::TYPE_STRING);
        }
        $sheet->getStyle("A5:{$lastCol}5")->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FFE65100']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF9C4']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFFECB3']]],
        ]);
        $sheet->getRowDimension(5)->setRowHeight(20);

        // Rows 6–55 — Empty data rows
        for ($row = 6; $row <= 55; $row++) {
            $bg = $row % 2 === 0 ? 'FFF5F5F5' : 'FFFFFFFF';
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFE0E0E0']]],
            ]);
            // Format no_telp & tgl_aktif as text to prevent auto-conversion
            $sheet->getStyle("I{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            $sheet->getStyle("J{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            $sheet->getRowDimension($row)->setRowHeight(18);
        }

        $sheet->freezePane('A5');
        $sheet->setAutoFilter("A4:{$lastCol}4");
    }

    private function buildInstructionSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Petunjuk Pengisian');
        $sheet->getColumnDimension('A')->setWidth(22);
        $sheet->getColumnDimension('B')->setWidth(55);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(42);

        $row = 1;

        // Title
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", 'PETUNJUK PENGISIAN — TEMPLATE IMPORT DATA RESTO');
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
            '4. Kolom nama_investor, nama_perusahaan, nama_brand, nama_pic HARUS persis sama dengan data yang ada di sistem (case-sensitive).',
            '5. Kolom tgl_aktif gunakan format DD-MM-YYYY. Contoh: 15-01-2026.',
            '6. Kolom no_telp — format sel Excel harus TEXT agar angka panjang tidak berubah ke notasi ilmiah.',
            '7. Kolom opsional dapat dikosongkan atau diisi tanda \'-\' (strip) — sistem akan memperlakukan keduanya sebagai tidak ada nilai.',
            '8. Maksimal 500 baris data per file.',
            '9. Simpan file sebagai .xlsx atau .csv sebelum diupload ke sistem.',
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
            ['nama_resto',       'Nama lengkap restoran',                         'Ya',       'Teks, maks 150 karakter. Contoh: Resto Maju Jaya'],
            ['nama_investor',    'Nama investor pemilik resto',                   'Opsional', 'Harus SAMA PERSIS dengan nama investor di sistem'],
            ['nama_perusahaan',  'Nama atau singkatan perusahaan pengelola',      'Opsional', 'Boleh nama lengkap atau singkatan. Contoh: PT Maju Jaya'],
            ['nama_brand',       'Nama brand / merek resto',                      'Opsional', 'Harus SAMA PERSIS dengan nama brand di sistem'],
            ['nama_pic',         'Nama karyawan sebagai PIC (penanggung jawab)',  'Opsional', 'Harus SAMA PERSIS dengan nama karyawan di sistem'],
            ['area',             'Area wilayah lokasi resto',                     'Opsional', 'Teks, maks 100 karakter. Contoh: Jakarta Pusat'],
            ['kota',             'Nama kota lokasi resto',                        'Opsional', 'Teks, maks 100 karakter. Contoh: Jakarta'],
            ['alamat',           'Alamat lengkap resto',                          'Opsional', 'Teks bebas. Contoh: Jl. Sudirman No. 1, Jakarta'],
            ['no_telp',          'Nomor telepon resto',                           'Opsional', 'Format sel harus TEXT. Contoh: 02112345678'],
            ['tgl_aktif',        'Tanggal mulai aktif beroperasi',                'Opsional', 'Format: DD-MM-YYYY. Contoh: 15-01-2026'],
            ['keterangan',       'Catatan atau keterangan tambahan',              'Opsional', 'Teks bebas'],
            ['status',           'Status aktif resto',                            'Opsional', '1 = Aktif (default), 0 = Tidak Aktif'],
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
            '• Jika nama_resto SUDAH ADA di sistem, data akan DIPERBARUI (update).',
            '• Jika nama_resto BELUM ADA di sistem, data baru akan DITAMBAHKAN (insert).',
            '• Kolom referensi (nama_investor, nama_perusahaan, nama_brand, nama_pic) yang tidak ditemukan di sistem akan menyebabkan baris tersebut GAGAL diimport.',
            '• Kolom referensi yang dikosongkan akan diabaikan (tidak wajib diisi).',
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

    private function importDate(mixed $val): ?string
    {
        $s = trim((string) $val);
        if ($s === '' || $s === '-') return null;

        // DD-MM-YYYY → Y-m-d
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return $s; // pass through, let validator catch invalid formats
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
