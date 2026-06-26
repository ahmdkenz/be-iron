<?php

namespace App\Domain\Master\Barang\Controllers;

use App\Domain\Master\Barang\DTO\BarangDTO;
use App\Domain\Master\Barang\Jobs\ImportBarangJob;
use App\Domain\Master\Barang\Requests\StoreBarangRequest;
use App\Domain\Master\Barang\Requests\UpdateBarangRequest;
use App\Domain\Master\Barang\Resources\BarangResource;
use App\Domain\Master\Barang\Services\BarangService;
use App\Http\Controllers\Controller;
use App\Models\BarangImportBatch;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BarangController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BarangService $service) {}

    public function index(Request $request): JsonResponse
    {
        $list = $this->service->paginate($request->only(['search', 'status', 'brand_id']));
        return $this->paginatedResponse($list->through(fn($b) => new BarangResource($b)));
    }

    public function store(StoreBarangRequest $request): JsonResponse
    {
        $this->forbidReadOnlyMutation();

        $barang = $this->service->create(BarangDTO::fromRequest($request->validated()));
        return $this->createdResponse(new BarangResource($barang), 'Barang berhasil dibuat');
    }

    public function show(int $id): JsonResponse
    {
        $barang = $this->service->findOrFail($id);
        return $this->successResponse(new BarangResource($barang));
    }

    public function update(UpdateBarangRequest $request, int $id): JsonResponse
    {
        $this->forbidReadOnlyMutation();

        $barang  = $this->service->findOrFail($id);
        $updated = $this->service->update($barang, BarangDTO::fromRequest($request->validated()));
        return $this->successResponse(new BarangResource($updated), 'Barang berhasil diperbarui');
    }

    public function destroy(int $id): JsonResponse
    {
        $this->forbidReadOnlyMutation();

        $barang = $this->service->findOrFail($id);
        $this->service->delete($barang);
        return $this->successResponse(null, 'Barang berhasil dihapus');
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
            "{$deleted} barang berhasil dihapus"
        );
    }

    public function externalCatalog(): JsonResponse
    {
        $data = Cache::remember('external_barang_catalog', now()->addMinutes(10), function () {
            try {
                $response = Http::timeout(10)->get('https://shz360.net/api/barang-catalog');
                return $response->successful() ? ($response->json('data') ?? []) : [];
            } catch (\Throwable $e) {
                return [];
            }
        });

        return $this->successResponse($data);
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

        $temp = tempnam(sys_get_temp_dir(), 'tpl_barang_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'template-barang.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    /**
     * Terima file import lalu proses di latar belakang (queue).
     * Mengembalikan batch_id yang dipakai frontend untuk polling progress.
     */
    public function import(Request $request): JsonResponse
    {
        $this->forbidReadOnlyMutation();

        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ]);

        // Bersihkan batch yang nyangkut (worker dihentikan host di tengah proses).
        BarangImportBatch::failStale();

        $path = $request->file('file')->store('barang-imports');

        $batch = BarangImportBatch::create([
            'user_id'   => auth()->id(),
            'file_path' => $path,
            'status'    => 'queued',
        ]);

        ImportBarangJob::dispatch($batch->id);

        return $this->successResponse([
            'batch_id' => $batch->id,
            'status'   => $batch->status,
        ], 'File diterima. Import sedang diproses di latar belakang.', 202);
    }

    /**
     * Status & progress sebuah batch import (di-poll frontend).
     */
    public function importStatus(string $id): JsonResponse
    {
        // Bersihkan batch yang nyangkut.
        BarangImportBatch::failStale();

        $batch = BarangImportBatch::find($id);
        if (!$batch) {
            return $this->notFoundResponse('Batch import tidak ditemukan');
        }

        $user = auth()->user();
        if ($batch->user_id !== $user->id && !RoleHelper::hasAnyRole($user, ['ADMIN', 'MANAGER', 'SUPERVISOR'])) {
            return $this->unauthorizedResponse();
        }

        return $this->successResponse([
            'batch_id'       => $batch->id,
            'status'         => $batch->status,
            'processed'      => $batch->processed,
            'progress_total' => $batch->total,
            'total'          => $batch->total_data,
            'inserted'       => $batch->inserted,
            'updated'        => $batch->updated,
            'failed'         => $batch->failed,
            'errors'         => $batch->errors ?? [],
            'message'        => $batch->message,
        ]);
    }

    private function buildDataSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Data Barang');

        $cols = [
            'A' => ['kode_barang',  18],
            'B' => ['nama_barang',  32],
            'C' => ['spesifikasi',  30],
            'D' => ['nama_brand',   22],
            'E' => ['keterangan',   28],
            'F' => ['status',       12],
        ];

        $lastCol = 'F';

        // Row 1 — Title
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'TEMPLATE IMPORT DATA BARANG');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        // Row 2 — Subtitle
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', 'Isi data barang di bawah ini. Kolom nama_barang wajib diisi. Kolom kode_barang wajib untuk data baru. Hapus baris [CONTOH] sebelum import. Lihat sheet "Petunjuk Pengisian" untuk panduan lengkap.');
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
            'A' => '[CONTOH] BRG-001',
            'B' => 'Nama Barang Contoh',
            'C' => '500ml / Merah',
            'D' => 'Nama Brand',
            'E' => 'Catatan opsional',
            'F' => 'Aktif',
        ];
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
        $sheet->setCellValue("A{$row}", 'PETUNJUK PENGISIAN — TEMPLATE IMPORT DATA BARANG');
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
            '4. Kolom nama_barang WAJIB diisi. Kolom kode_barang WAJIB untuk data baru (belum pernah ada di sistem).',
            '5. Kolom kode_barang akan diubah otomatis ke UPPERCASE. Untuk data yang sudah ada, kode_barang TIDAK akan diperbarui.',
            '6. Kolom nama_brand HARUS persis sama dengan nama brand yang ada di sistem (case-insensitive).',
            '7. Kolom status: isi "Aktif" atau "Nonaktif" (atau 1/0). Jika kosong, default = Aktif.',
            '8. Kolom opsional dapat dikosongkan atau diisi tanda \'-\' (strip) — sistem akan memperlakukan keduanya sebagai tidak ada nilai.',
            '9. Simpan file sebagai .xlsx atau .csv sebelum diupload ke sistem.',
        ];

        foreach ($steps as $step) {
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->setCellValue("A{$row}", '  ' . $step);
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font'      => ['size' => 9],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF8F9FA']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFE0E0E0']]],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(20);
            $row++;
        }
        $row++;

        // Section: Deskripsi Kolom
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", '  DESKRIPSI KOLOM');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1976D2']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++;

        // Column headers for table
        foreach (['Kolom', 'Deskripsi', 'Wajib', 'Contoh Nilai'] as $i => $h) {
            $col = chr(ord('A') + $i);
            $sheet->setCellValue("{$col}{$row}", $h);
            $sheet->getStyle("{$col}{$row}")->applyFromArray([
                'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FFFFFFFF']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF42A5F5']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1976D2']]],
            ]);
        }
        $sheet->getRowDimension($row)->setRowHeight(20);
        $row++;

        $colDefs = [
            ['kode_barang',  'Kode unik barang. Diubah otomatis ke UPPERCASE. Tidak diperbarui untuk data lama.',                              'Wajib (baru)',  'BRG-001'],
            ['nama_barang',  'Nama barang. Kunci upsert: jika cocok (case-insensitive) → UPDATE, jika baru → CREATE.',                         'Wajib',         'Minyak Goreng 2L'],
            ['spesifikasi',  'Spesifikasi teknis atau deskripsi singkat barang. Teks bebas.',                                                   'Opsional',      '2 Liter / Kemasan Botol'],
            ['nama_brand',   'Nama brand harus persis sama (case-insensitive) dengan brand yang ada di sistem. Kosongkan jika tidak ada brand.', 'Opsional',      'Brand ABC'],
            ['keterangan',   'Catatan tambahan untuk barang. Teks bebas.',                                                                      'Opsional',      'Untuk kebutuhan restoran'],
            ['status',       'Status aktif barang. Isi "Aktif"/"Nonaktif" atau "1"/"0". Jika kosong, default = Aktif.',                         'Opsional',      'Aktif'],
        ];

        foreach ($colDefs as $i => $def) {
            $bg = $i % 2 === 0 ? 'FFFFFFFF' : 'FFF5F5F5';
            foreach ([$def[0], $def[1], $def[2], $def[3]] as $j => $val) {
                $col = chr(ord('A') + $j);
                $sheet->setCellValue("{$col}{$row}", $val);
                $sheet->getStyle("{$col}{$row}")->applyFromArray([
                    'font'      => ['size' => 9],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFE0E0E0']]],
                ]);
            }
            $sheet->getRowDimension($row)->setRowHeight(30);
            $row++;
        }
        $row++;

        // Section: Perilaku Upsert
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", '  PERILAKU UPSERT (UPDATE/INSERT OTOMATIS)');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF388E3C']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++;

        $upsertNotes = [
            'Sistem mencocokkan baris dengan data yang ada menggunakan kolom nama_barang (case-insensitive).',
            'Jika nama_barang cocok → data yang ada akan DIPERBARUI (UPDATE). Kolom kode_barang tidak akan diubah.',
            'Jika nama_barang belum ada → data BARU akan DITAMBAHKAN (INSERT). Kolom kode_barang wajib diisi.',
            'Baris yang gagal tidak membatalkan baris lain — data valid tetap tersimpan, baris gagal akan dilaporkan.',
        ];

        foreach ($upsertNotes as $note) {
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->setCellValue("A{$row}", '  • ' . $note);
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font'      => ['size' => 9],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF1F8E9']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFAED581']]],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(22);
            $row++;
        }
    }

    private function forbidReadOnlyMutation(): void
    {
        abort_if(
            $this->isReadOnlyRole(),
            403,
            'Role AR dan Direktur hanya memiliki akses lihat data barang'
        );
    }

    private function isReadOnlyRole(): bool
    {
        $user = auth()->user();

        return RoleHelper::isArOnly($user);
    }
}
