<?php

namespace App\Domain\Master\Resto\Controllers;

use App\Domain\Master\Resto\DTO\RestoDTO;
use App\Domain\Master\Resto\Requests\StoreRestoRequest;
use App\Domain\Master\Resto\Requests\UpdateRestoRequest;
use App\Domain\Master\Resto\Resources\RestoResource;
use App\Domain\Master\Resto\Jobs\ImportRestoJob;
use App\Domain\Master\Resto\Services\RestoService;
use App\Http\Controllers\Controller;
use App\Models\RestoImportBatch;
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
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
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

    public function importTemplate(): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

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
        RestoImportBatch::failStale();

        $path = $request->file('file')->store('resto-imports');

        $batch = RestoImportBatch::create([
            'user_id'   => auth()->id(),
            'file_path' => $path,
            'status'    => 'queued',
        ]);

        ImportRestoJob::dispatch($batch->id);

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
        // Bersihkan batch yang nyangkut (worker dihentikan host di tengah proses).
        RestoImportBatch::failStale();

        $batch = RestoImportBatch::find($id);
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
        $sheet->setTitle('Data Resto');

        $cols = [
            'A' => ['kode_resto',        20],
            'B' => ['nama_resto',        28],
            'C' => ['nama_investor',     26],
            'D' => ['nama_entitas',      26],
            'E' => ['nama_brand',        20],
            'F' => ['nama_pic',          26],
            'G' => ['supervisor',        24],
            'H' => ['no_hp_supervisor',  18],
            'I' => ['stokis',            20],
            'J' => ['area',              18],
            'K' => ['kota',              18],
            'L' => ['alamat',            35],
            'M' => ['no_telp',           18],
            'N' => ['tgl_aktif',         16],
            'O' => ['keterangan',        25],
            'P' => ['status',            10],
        ];

        $lastCol = 'P';

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
        $sheet->setCellValue('A2', 'Isi data resto di bawah ini. Kolom nama_resto dan kode_resto wajib diisi untuk data baru. Hapus baris [CONTOH] sebelum import. Lihat sheet "Petunjuk Pengisian" untuk panduan lengkap.');
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
            'A' => '[CONTOH] KD-001',
            'B' => 'Resto Contoh',
            'C' => 'Nama Investor',
            'D' => 'Nama Entitas',
            'E' => 'Nama Brand',
            'F' => 'Nama Karyawan PIC',
            'G' => 'Nama Supervisor',
            'H' => '08123456789',
            'I' => 'STOKIS-001',
            'J' => 'Jakarta Pusat',
            'K' => 'Jakarta',
            'L' => 'Jl. Contoh No. 1',
            'M' => '02112345678',
            'N' => '01-01-2026',
            'O' => 'Catatan opsional',
            'P' => '1',
        ];
        // Set text format for numeric-sensitive columns BEFORE writing values to prevent Excel auto-conversion
        $sheet->getStyle('H5')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle('M5')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle('N5')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

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
            // Format no_hp_supervisor, no_telp & tgl_aktif as text to prevent auto-conversion
            $sheet->getStyle("H{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            $sheet->getStyle("M{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            $sheet->getStyle("N{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
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
            '4. Kolom nama_resto dan kode_resto WAJIB diisi untuk data baru. kode_resto diisi manual sesuai keinginan (contoh: KD-001).',
            '5. Kolom nama_investor, nama_entitas, nama_brand, nama_pic HARUS persis sama dengan data yang ada di sistem (case-sensitive).',
            '6. Kolom tgl_aktif gunakan format DD-MM-YYYY. Contoh: 15-01-2026.',
            '7. Kolom no_telp dan no_hp_supervisor — format sel Excel harus TEXT agar angka panjang tidak berubah ke notasi ilmiah.',
            '8. Kolom opsional dapat dikosongkan atau diisi tanda \'-\' (strip) — sistem akan memperlakukan keduanya sebagai tidak ada nilai.',
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
            ['kode_resto',        'Kode unik restoran (diisi manual)',             'Ya (data baru)',   'Teks, maks 100 karakter. Contoh: KD-001. Tidak diperbarui saat update.'],
            ['nama_resto',        'Nama lengkap restoran',                         'Ya',               'Teks, maks 150 karakter. Contoh: Resto Maju Jaya'],
            ['nama_investor',     'Nama investor pemilik resto',                   'Opsional',         'Harus SAMA PERSIS dengan nama investor di sistem'],
            ['nama_entitas',      'Nama atau singkatan entitas pengelola',         'Opsional',         'Boleh nama lengkap atau singkatan. Contoh: PT Maju Jaya'],
            ['nama_brand',        'Nama brand / merek resto',                      'Opsional',         'Harus SAMA PERSIS dengan nama brand di sistem'],
            ['nama_pic',          'Nama karyawan sebagai PIC (penanggung jawab)',  'Opsional',         'Harus SAMA PERSIS dengan nama karyawan di sistem'],
            ['supervisor',        'Nama supervisor resto',                         'Opsional',         'Teks, maks 150 karakter. Contoh: Budi Santoso'],
            ['no_hp_supervisor',  'Nomor HP supervisor (No SPV)',                  'Opsional',         'Format sel harus TEXT. Contoh: 08123456789'],
            ['stokis',            'Kode atau nama STOKIS',                         'Opsional',         'Teks, maks 150 karakter. Contoh: STOKIS-001'],
            ['area',              'Area wilayah lokasi resto',                     'Opsional',         'Teks, maks 100 karakter. Contoh: Jakarta Pusat'],
            ['kota',              'Nama kota lokasi resto',                        'Opsional',         'Teks, maks 100 karakter. Contoh: Jakarta'],
            ['alamat',            'Alamat lengkap resto',                          'Opsional',         'Teks bebas. Contoh: Jl. Sudirman No. 1, Jakarta'],
            ['no_telp',           'Nomor telepon resto',                           'Opsional',         'Format sel harus TEXT. Contoh: 02112345678'],
            ['tgl_aktif',         'Tanggal mulai aktif beroperasi',                'Opsional',         'Format: DD-MM-YYYY. Contoh: 15-01-2026'],
            ['keterangan',        'Catatan atau keterangan tambahan',              'Opsional',         'Teks bebas'],
            ['status',            'Status aktif resto',                            'Opsional',         '1 = Aktif (default), 0 = Tidak Aktif'],
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
            '• Jika nama_resto SUDAH ADA di sistem, data akan DIPERBARUI (update). kode_resto TIDAK akan diubah saat update.',
            '• Jika nama_resto BELUM ADA di sistem, data baru akan DITAMBAHKAN (insert). kode_resto WAJIB diisi untuk data baru.',
            '• Kolom referensi (nama_investor, nama_entitas, nama_brand, nama_pic) yang tidak ditemukan di sistem akan menyebabkan baris tersebut GAGAL diimport.',
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
