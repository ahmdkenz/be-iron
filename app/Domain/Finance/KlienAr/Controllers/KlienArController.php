<?php

namespace App\Domain\Finance\KlienAr\Controllers;

use App\Domain\Finance\KlienAr\DTO\KlienArDTO;
use App\Domain\Finance\KlienAr\Requests\StoreKlienArRequest;
use App\Domain\Finance\KlienAr\Requests\UpdateKlienArRequest;
use App\Domain\Finance\KlienAr\Resources\KlienArResource;
use App\Domain\Finance\KlienAr\Services\KlienArService;
use App\Http\Controllers\Controller;
use App\Models\KlienAr;
use App\Models\Karyawan;
use App\Models\Resto;
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

class KlienArController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly KlienArService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'status', 'perusahaan_id', 'karyawan_ar_id']);
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
        abort_if(
            $this->isDirectorOnly(),
            403,
            'Direktur hanya memiliki akses lihat data Client'
        );

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

            $rowData = [
                'A' => [$klien->kode_klien,                                    DataType::TYPE_STRING],
                'B' => [$klien->nama_klien,                                    DataType::TYPE_STRING],
                'C' => [$klien->tipe_klien,                                    DataType::TYPE_STRING],
                'D' => [$segment,                                              DataType::TYPE_STRING],
                'E' => [$klien->resto?->nama_resto ?? '-',                     DataType::TYPE_STRING],
                'F' => [$klien->resto?->investor?->nama_investor ?? '-',       DataType::TYPE_STRING],
                'G' => [$klien->no_npwp ?? '-',                               DataType::TYPE_STRING],
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

    public function importTemplate(): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $spreadsheet = new Spreadsheet();

        $this->buildDataSheet($spreadsheet->getActiveSheet());
        $this->buildInstructionSheet($spreadsheet->createSheet());

        $spreadsheet->setActiveSheetIndex(0);

        $temp = tempnam(sys_get_temp_dir(), 'tpl_klienar_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'template-klien-ar.xlsx', [
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

            $namaKlien       = trim((string) ($row[1] ?? ''));
            $tipeKlien       = strtoupper(trim((string) ($row[2] ?? '')));
            $namaResto       = $this->importValue($row[3] ?? '');
            $namaKaryawanAr  = $this->importValue($row[4] ?? '');

            // Lookup Resto
            $restoId = null;
            if ($namaResto) {
                $resto = Resto::where('nama_resto', $namaResto)->latest()->first();
                if (!$resto) {
                    $errors[] = ['row' => $lineNumber, 'message' => "Resto '{$namaResto}' tidak ditemukan di sistem."];
                    continue;
                }
                $restoId = $resto->id;
            } elseif ($tipeKlien === 'RESTO') {
                $errors[] = ['row' => $lineNumber, 'message' => "Kolom nama_resto wajib diisi untuk tipe klien {$tipeKlien}."];
                continue;
            }

            // Lookup Karyawan AR
            $karyawanArId = null;
            if ($namaKaryawanAr) {
                $karyawan = Karyawan::where('nama_karyawan', $namaKaryawanAr)->latest()->first();
                if (!$karyawan) {
                    $errors[] = ['row' => $lineNumber, 'message' => "Karyawan AR '{$namaKaryawanAr}' tidak ditemukan di sistem."];
                    continue;
                }
                $karyawanArId = $karyawan->id;
            }

            $data = [
                'kode_klien'     => $this->importValue($row[0] ?? ''),
                'nama_klien'     => $namaKlien,
                'tipe_klien'     => $tipeKlien,
                'resto_id'       => $restoId,
                'karyawan_ar_id' => $karyawanArId,
                'no_npwp'        => $this->importValue($row[5] ?? ''),
                'no_wa'          => $this->importValue($row[6] ?? ''),
                'status'         => isset($row[7]) && trim((string) $row[7]) !== '' ? (bool) (int) $row[7] : true,
            ];

            $validator = Validator::make($data, [
                'kode_klien'     => ['required', 'string', 'max:30'],
                'nama_klien'     => ['required', 'string', 'max:150'],
                'tipe_klien'     => ['required', 'in:PT,RESTO'],
                'resto_id'       => ['nullable', 'integer'],
                'karyawan_ar_id' => ['required', 'integer'],
                'no_npwp'        => ['nullable', 'string', 'max:30'],
                'no_wa'          => ['nullable', 'string', 'max:20'],
                'status'         => ['nullable', 'boolean'],
            ]);

            if ($validator->fails()) {
                $errors[] = ['row' => $lineNumber, 'message' => implode('; ', $validator->errors()->all())];
                continue;
            }

            $existing = KlienAr::where('kode_klien', $data['kode_klien'])->latest()->first();
            if (!$existing) {
                $existing = KlienAr::where('nama_klien', $data['nama_klien'])
                    ->where('tipe_klien', $data['tipe_klien'])
                    ->latest()
                    ->first();
            }

            if ($existing) {
                $this->service->update($existing, KlienArDTO::fromRequest($data));
                $updatedCount++;
            } else {
                $this->service->create(KlienArDTO::fromRequest($data));
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

    private function buildDataSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Data Client');

        $cols = [
            'A' => ['kode_resto',       20],
            'B' => ['nama_klien',       28],
            'C' => ['tipe_klien',       14],
            'D' => ['nama_resto',       28],
            'E' => ['nama_karyawan_ar', 26],
            'F' => ['no_npwp',          22],
            'G' => ['no_wa',            18],
            'H' => ['status',           10],
        ];

        // Row 1 — Title
        $sheet->mergeCells('A1:H1');
        $sheet->setCellValue('A1', 'TEMPLATE IMPORT DATA Client');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        // Row 2 — Subtitle
        $sheet->mergeCells('A2:H2');
        $sheet->setCellValue('A2', 'Isi data Client di bawah ini. Hapus baris [CONTOH] sebelum import. Lihat sheet "Petunjuk Pengisian" untuk panduan lengkap.');
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
        $sheet->getStyle('A4:H4')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1976D2']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF0D47A1']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(24);

        // Row 5 — Example row
        $example = [
            'A' => '[CONTOH] AR-B2C-001',
            'B' => 'Budi Santoso',
            'C' => 'RESTO',
            'D' => 'Warung Makan Enak',
            'E' => 'Andi Karyawan AR',
            'F' => '12.345.678.9-012.000',
            'G' => '08123456789',
            'H' => '1',
        ];
        foreach ($example as $col => $val) {
            $sheet->getCell("{$col}5")->setValueExplicit($val, DataType::TYPE_STRING);
        }
        $sheet->getStyle('A5:H5')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FFE65100']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF9C4']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFFECB3']]],
        ]);
        $sheet->getRowDimension(5)->setRowHeight(20);

        // Rows 6–55 — Empty template rows
        for ($row = 6; $row <= 55; $row++) {
            $bg = $row % 2 === 0 ? 'FFF5F5F5' : 'FFFFFFFF';
            $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFE0E0E0']]],
            ]);
            // Format no_wa as TEXT to prevent scientific notation
            $sheet->getStyle("G{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            $sheet->getRowDimension($row)->setRowHeight(18);
        }

        $sheet->freezePane('A5');
        $sheet->setAutoFilter('A4:H4');
    }

    private function buildInstructionSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Petunjuk Pengisian');
        $sheet->getColumnDimension('A')->setWidth(24);
        $sheet->getColumnDimension('B')->setWidth(50);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(42);

        $row = 1;

        // Title
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", 'PETUNJUK PENGISIAN — TEMPLATE IMPORT DATA Client');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(34);
        $row += 2;

        // Section: Cara Pengisian
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
            '4. Kolom opsional dapat dikosongkan atau diisi tanda \'-\' — sistem akan memperlakukan keduanya sebagai tidak ada nilai.',
            '5. Kolom kode_klien WAJIB diisi dengan format AR-B2C-xxx (B2C) atau AR-B2B-xxx (B2B). Contoh: AR-B2C-001.',
            '6. Kolom tipe_klien harus diisi persis salah satu dari: RESTO (B2C) atau PT (B2B).',
            '7. Untuk tipe RESTO, kolom nama_resto WAJIB diisi persis sesuai nama resto di sistem.',
            '8. Kolom nama_karyawan_ar WAJIB diisi persis sesuai nama karyawan AR di sistem.',
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

        // Section: Keterangan Kolom
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
            ['kode_resto',       'Kode unik Client (Resto)',                     'Ya',       'Format: AR-B2C-xxx (B2C) / AR-B2B-xxx (B2B). Contoh: AR-B2C-001'],
            ['nama_klien',       'Nama investor / kontak billing klien',        'Ya',       'Teks, maks 150 karakter. Contoh: Budi Santoso'],
            ['tipe_klien',       'Jenis/tipe Client',                           'Ya',       'Isi persis salah satu: RESTO (B2C) atau PT (B2B)'],
            ['nama_resto',       'Nama resto sesuai data di sistem',            'Jika B2C', 'Wajib untuk tipe RESTO. Kosongkan jika PT'],
            ['nama_karyawan_ar', 'Nama karyawan yang bertugas sebagai PIC AR',  'Ya',       'Harus sesuai persis dengan nama karyawan di sistem'],
            ['no_npwp',          'Nomor NPWP klien',                            'Opsional', 'Format: XX.XXX.XXX.X-XXX.XXX. Contoh: 12.345.678.9-012.000'],
            ['no_wa',            'Nomor WhatsApp untuk notifikasi tagihan',     'Opsional', 'Awali dengan 08 atau 62. Contoh: 08123456789'],
            ['status',           'Status aktif klien',                          'Opsional', '1 = Aktif (default), 0 = Nonaktif'],
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

        // Section: Catatan Penting
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
            '• kode_resto WAJIB diisi — kode tidak di-generate otomatis, harus diisi manual sesuai format.',
            '• Jika kode_resto SUDAH ADA di sistem, data akan DIPERBARUI (update).',
            '• Jika kode_resto BELUM ADA di sistem, data baru DITAMBAHKAN dengan kode yang diberikan.',
            '• nama_resto yang tidak ditemukan di sistem akan menyebabkan baris tersebut GAGAL diproses.',
            '• nama_karyawan_ar yang tidak ditemukan di sistem akan menyebabkan baris tersebut GAGAL diproses.',
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

            $cells     = array_slice($cells, 0, 8);
            $firstCell = trim($cells[0] ?? '');

            if (!$headerFound) {
                if (strtolower($firstCell) === 'kode_klien') {
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

    private function importValue(mixed $val): ?string
    {
        $s = trim((string) $val);
        return ($s === '' || $s === '-') ? null : $s;
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
