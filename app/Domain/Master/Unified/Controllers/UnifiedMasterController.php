<?php

namespace App\Domain\Master\Unified\Controllers;

use App\Domain\Master\Unified\Jobs\ImportMasterJob;
use App\Http\Controllers\Controller;
use App\Models\ImportMasterBatch;
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

class UnifiedMasterController extends Controller
{
    use ApiResponse;

    public function importTemplate(): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $spreadsheet = new Spreadsheet();

        // Sheet MASTER INVOICE sengaja tidak lagi ada di template ini — invoice
        // punya template & alur import aman sendiri di tab "Import Master Invoice"
        // (lihat InvoiceImportTemplateService).
        $this->buildMasterDataSheet($spreadsheet->getActiveSheet());
        $this->buildMasterBarangSheet($spreadsheet->createSheet());
        $this->buildInstructionSheet($spreadsheet->createSheet());

        $spreadsheet->setActiveSheetIndex(0);

        $temp = tempnam(sys_get_temp_dir(), 'tpl_master_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'template-import-master-data.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    public function import(Request $request): JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $user = auth()->user();
        if (!RoleHelper::hasAnyRole($user, ['ADMIN', 'MANAGER', 'SUPERVISOR'])) {
            return $this->unauthorizedResponse();
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ]);

        ImportMasterBatch::failStale();

        $path = $request->file('file')->store('master-imports');

        $batch = ImportMasterBatch::create([
            'user_id'   => auth()->id(),
            'file_path' => $path,
            'status'    => 'queued',
        ]);

        ImportMasterJob::dispatch($batch->id);

        return $this->successResponse([
            'batch_id' => $batch->id,
            'status'   => $batch->status,
        ], 'File diterima. Import sedang diproses di latar belakang.', 202);
    }

    public function latestImport(): JsonResponse
    {
        $batch = ImportMasterBatch::where('status', 'completed')
            ->with(['user:id,username,karyawan_id', 'user.karyawan:id,nama_karyawan'])
            ->latest('updated_at')
            ->first();

        if (!$batch) {
            return $this->successResponse(null);
        }

        return $this->successResponse([
            'imported_at'       => $batch->updated_at->toIso8601String(),
            'imported_by'       => $batch->user?->name,
            'investor_inserted' => $batch->investor_inserted,
            'investor_updated'  => $batch->investor_updated,
            'investor_failed'   => $batch->investor_failed,
            'resto_inserted'    => $batch->resto_inserted,
            'resto_updated'     => $batch->resto_updated,
            'resto_failed'      => $batch->resto_failed,
            'klien_inserted'    => $batch->klien_inserted,
            'klien_updated'     => $batch->klien_updated,
            'klien_failed'      => $batch->klien_failed,
            'klien_skipped'     => $batch->klien_skipped,
            'barang_inserted'   => $batch->barang_inserted,
            'barang_updated'    => $batch->barang_updated,
            'barang_skipped'    => $batch->barang_skipped,
            'barang_failed'     => $batch->barang_failed,
        ]);
    }

    public function importStatus(string $id): JsonResponse
    {
        ImportMasterBatch::failStale();

        $batch = ImportMasterBatch::find($id);
        if (!$batch) {
            return $this->notFoundResponse('Batch import tidak ditemukan');
        }

        $user = auth()->user();
        if ($batch->user_id !== $user->id && !RoleHelper::hasAnyRole($user, ['ADMIN', 'MANAGER', 'SUPERVISOR'])) {
            return $this->unauthorizedResponse();
        }

        return $this->successResponse([
            'batch_id'          => $batch->id,
            'status'            => $batch->status,
            'master_total'      => $batch->master_total,
            'master_processed'  => $batch->master_processed,
            'investor_inserted' => $batch->investor_inserted,
            'investor_updated'  => $batch->investor_updated,
            'investor_failed'   => $batch->investor_failed,
            'resto_inserted'    => $batch->resto_inserted,
            'resto_updated'     => $batch->resto_updated,
            'resto_failed'      => $batch->resto_failed,
            'klien_inserted'    => $batch->klien_inserted,
            'klien_updated'     => $batch->klien_updated,
            'klien_failed'      => $batch->klien_failed,
            'klien_skipped'     => $batch->klien_skipped,
            'barang_total'      => $batch->barang_total,
            'barang_processed'  => $batch->barang_processed,
            'barang_inserted'   => $batch->barang_inserted,
            'barang_updated'    => $batch->barang_updated,
            'barang_skipped'    => $batch->barang_skipped,
            'barang_failed'     => $batch->barang_failed,
            'errors'            => $batch->errors ?? [],
            'message'           => $batch->message,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Template builders
    // ──────────────────────────────────────────────────────────────

    private function buildMasterDataSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('MASTER DATA');

        $cols = [
            'A'  => ['nama_investor (*)', 30],
            'B'  => ['ktp',             20],
            'C'  => ['npwp',            24],
            'D'  => ['no_hp',           16],
            'E'  => ['pengelola',       24],
            'F'  => ['no_hp_pengelola', 20],
            'G'  => ['kode_cabang',     18],
            'H'  => ['id_cabang',       18],
            'I'  => ['kode_resto (*)',      18],
            'J'  => ['nama_cabang (*)',     30],
            'K'  => ['nama_entitas (*)',    26],
            'L'  => ['nama_brand',      20],
            'M'  => ['nama_pic (*)',        24],
            'N'  => ['supervisor',      24],
            'O'  => ['no_hp_supervisor',18],
            'P'  => ['stokis',          22],
            'Q'  => ['area',            18],
            'R'  => ['kota',            16],
            'S'  => ['alamat',          30],
            'T'  => ['no_telp',         16],
            'U'  => ['tgl_aktif',       14],
            'V'  => ['keterangan',      26],
            'W'  => ['pic_ar (*)',          26],
            'X'  => ['tipe_klien (*)',      18],
            'Y'  => ['status',          10],
        ];

        $lastCol = 'Y';

        // Row 1 — Title
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'TEMPLATE IMPORT MASTER DATA');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        // Row 2 — Subtitle
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', 'Satu baris = 1 outlet (Investor + Resto + Client AR). Kolom bertanda (*) wajib diisi — sebagian bersifat kondisional: nama_entitas & pic_ar wajib jika tipe_klien=PT/B2B; nama_pic wajib jika tipe_klien=RESTO/B2C. tipe_klien boleh diisi RESTO/B2C atau PT/B2B (RESTO & B2C sama artinya, begitu juga PT & B2B). Kolom nama_pic & pic_ar boleh diisi NAMA karyawan, NIK, atau kombinasi "Nama (NIK)"/"Nama - NIK"/"Nama / NIK". NPWP & kontak Client AR otomatis dari Investor baris ini (npwp → no_npwp, no_hp → no_wa) untuk semua tipe. Hanya role ADMIN/MANAGER/SUPERVISOR. Lihat sheet "Petunjuk Pengisian".');
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF37474F']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE3F2FD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(30);

        // Row 3 — Spacer
        $sheet->getRowDimension(3)->setRowHeight(8);

        // Row 4 — Headers
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

        // Row 5 — Example
        $example = [
            'A'  => '[CONTOH] Nama Investor',
            'B'  => '3273012345678901',
            'C'  => '12.345.678.9-012.000',
            'D'  => '08123456789',
            'E'  => 'Nama Pengelola',
            'F'  => '08198765432',
            'G'  => 'CB-001',
            'H'  => 'ID-CB-001',
            'I'  => 'KD-001',
            'J'  => 'Nama Cabang / Resto',
            'K'  => 'Nama Entitas PT',
            'L'  => 'Nama Brand',
            'M'  => 'Nama PIC Resto',
            'N'  => 'Nama Supervisor',
            'O'  => '08111222333',
            'P'  => 'Nama Stokis',
            'Q'  => 'Jakarta Pusat',
            'R'  => 'Jakarta',
            'S'  => 'Jl. Contoh No. 1',
            'T'  => '02112345678',
            'U'  => '01-01-2026',
            'V'  => 'Keterangan opsional',
            'W'  => 'Nama Karyawan AR',
            'X'  => 'RESTO/B2C',
            'Y'  => '1',
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

        // Rows 6–55 — Data rows
        for ($row = 6; $row <= 55; $row++) {
            $bg = $row % 2 === 0 ? 'FFF5F5F5' : 'FFFFFFFF';
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFE0E0E0']]],
            ]);
            // Format as text: kolom-kolom nomor yang mungkin diawali 0
            foreach (['B', 'C', 'D', 'F', 'O', 'T', 'U'] as $col) {
                $sheet->getStyle("{$col}{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
            $sheet->getRowDimension($row)->setRowHeight(18);
        }

        $sheet->freezePane('A5');
        $sheet->setAutoFilter("A4:{$lastCol}4");
    }

    private function buildMasterBarangSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('MASTER BARANG');

        $cols = [
            'A' => ['kode_barang (*)', 20],
            'B' => ['nama_barang (*)', 32],
            'C' => ['spesifikasi', 30],
            'D' => ['keterangan',  28],
            'E' => ['status',      12],
        ];

        $lastCol = 'E';

        // Row 1 — Title
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'TEMPLATE IMPORT MASTER BARANG');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E7D32']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        // Row 2 — Subtitle
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', 'Isi data barang/produk di bawah ini. kode_barang wajib untuk data baru. Upsert berdasarkan nama_barang (case-insensitive).');
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF37474F']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8F5E9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(28);

        // Row 3 — Spacer
        $sheet->getRowDimension(3)->setRowHeight(8);

        // Row 4 — Headers
        foreach ($cols as $col => [$name, $width]) {
            $sheet->setCellValue("{$col}4", $name);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF388E3C']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1B5E20']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(24);

        // Row 5 — Example
        $example = [
            'A' => '[CONTOH] KD-001',
            'B' => 'Nama Barang Contoh',
            'C' => 'Spesifikasi produk',
            'D' => 'Keterangan opsional',
            'E' => '1',
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

        // Rows 6–55 — Data rows
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
        $sheet->getColumnDimension('A')->setWidth(24);
        $sheet->getColumnDimension('B')->setWidth(48);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(36);

        $row = 1;

        // Title
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", 'PETUNJUK PENGISIAN — TEMPLATE IMPORT MASTER DATA');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(34);
        $row += 2;

        // ─── Cara Pengisian
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
            '1. File ini berisi 3 sheet: "MASTER DATA" (Investor+Resto+Client AR), "MASTER BARANG" (Barang/Produk), dan "Petunjuk Pengisian".',
            '2. Urutan import: MASTER DATA → MASTER BARANG. Invoice TIDAK lagi diimport dari file ini — pakai tab "Import Master Invoice" dengan template terpisah.',
            '3. Sheet MASTER DATA: isi satu baris per outlet (1 Investor + 1 Resto + 1 Client AR). Kolom bertanda (*) pada header wajib diisi — sebagian bersifat kondisional mengikuti tipe_klien (lihat tabel Deskripsi Kolom di bawah).',
            '4. tipe_klien boleh diisi RESTO/B2C atau PT/B2B (RESTO & B2C sama artinya, begitu juga PT & B2B — sistem otomatis menyamakan). tipe_klien=PT/B2B: nama_entitas & pic_ar wajib diisi (pic_ar menjadi PIC AR Client). tipe_klien=RESTO/B2C: nama_pic wajib diisi (menjadi PIC Resto sekaligus PIC AR Client); jika nama_pic kosong, pic_ar boleh dipakai sebagai pengganti. Jika nama_pic & pic_ar sama-sama diisi tapi menunjuk karyawan berbeda, baris akan ditolak.',
            '5. Kolom nama_pic dan pic_ar boleh diisi NAMA KARYAWAN, NIK, atau kombinasi keduanya ("Andi Wijaya (3273010101900001)", "Andi Wijaya - 3273010101900001", atau "Andi Wijaya / 3273010101900001") — sistem mencari otomatis. Untuk format kombinasi, NIK dipakai sebagai identitas utama: jika NIK ditemukan tapi nama di Excel berbeda dari Master Karyawan, nama karyawan akan DIPERBARUI mengikuti Excel (NIK tidak pernah berubah, dan karyawan baru tidak akan dibuat otomatis). Nama Client AR otomatis: RESTO = nama_investor, PT = nama_entitas.',
            '6. Sheet MASTER BARANG: kode_barang wajib untuk barang baru. Kolom: kode_barang, nama_barang, spesifikasi, keterangan, status.',
            '7. INVOICE: gunakan tab "Import Master Invoice" (template & alur terpisah). Di sana sistem membaca & mengklasifikasi dulu, baru menulis data setelah Anda menekan "Proses Data Aman" — invoice yang sudah dibayar/dicocokkan rekening koran tidak pernah ditimpa diam-diam.',
            '8. Sheet MASTER INVOICE pada file lama akan DIABAIKAN oleh tab Import Master Data.',
            '9. Hapus baris [CONTOH] sebelum upload atau biarkan (sistem otomatis mengabaikan).',
            '10. Kolom status: 1 = Aktif (default), 0 = Nonaktif.',
            '11. Upload hanya bisa dilakukan oleh role ADMIN, MANAGER, atau SUPERVISOR.',
            '12. Upload file di halaman "Import Master Data" lalu pantau progress secara real-time.',
        ];

        foreach ($steps as $step) {
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->setCellValue("A{$row}", '  ' . $step);
            $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("A{$row}")->getFont()->setSize(9);
            $bg = $row % 2 === 0 ? 'FFF5F5F5' : 'FFFFFFFF';
            $sheet->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bg);
            $sheet->getRowDimension($row)->setRowHeight(18);
            $row++;
        }

        $row++;

        // ─── Deskripsi Kolom Sheet MASTER DATA
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", '  DESKRIPSI KOLOM — Sheet MASTER DATA');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1976D2']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++;

        // Header tabel
        foreach (['A' => 'Kolom', 'B' => 'Keterangan', 'C' => 'Wajib', 'D' => 'Contoh'] as $col => $label) {
            $sheet->setCellValue("{$col}{$row}", $label);
        }
        $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF42A5F5']],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(20);
        $row++;

        $masterDataCols = [
            ['nama_investor',   'Nama investor (upsert key bersama kode+id_cabang)',               'Ya',    'Budi Santoso'],
            ['ktp',             'Nomor KTP investor (boleh sama antar cabang)',                    'Opsional',               '3273012345678901'],
            ['npwp',            'Nomor NPWP investor (boleh sama antar cabang)',                   'Opsional',               '12.345.678.9-012.000'],
            ['no_hp',           'Nomor HP investor',                                               'Opsional',               '08123456789'],
            ['pengelola',       'Nama pengelola investor',                                         'Opsional',               'Ahmad'],
            ['no_hp_pengelola', 'Nomor HP pengelola investor',                                     'Opsional',               '08198765432'],
            ['kode_cabang',     'Kode cabang investor',                                            'Opsional',               'CB-001'],
            ['id_cabang',       'ID cabang investor',                                              'Opsional',               'ID-CB-001'],
            ['kode_resto',      'Kode resto — identitas unik outlet. Wajib untuk Resto baru; untuk edit resto lama boleh dikosongkan (fallback ke nama_cabang), tapi disarankan selalu diisi.', 'Ya', 'KD-001'],
            ['nama_cabang',     'Nama cabang/resto (upsert key Resto)',                            'Ya', 'Warung Makan Enak'],
            ['nama_entitas',    'Nama perusahaan/singkatan (lookup) — WAJIB jika tipe_klien=PT/B2B',  'Ya (PT/B2B)',                'PT Maju Bersama'],
            ['nama_brand',      'Nama brand untuk Resto (lookup)',                                 'Opsional',               'Brand X'],
            ['nama_pic',        'Nama PIC Resto — boleh diisi NAMA karyawan, NIK, atau kombinasi "Nama (NIK)"/"Nama - NIK"/"Nama / NIK" (lookup). Kombinasi memakai NIK sebagai identitas utama — kalau nama di Excel beda dari Master Karyawan, nama akan ditimpa mengikuti Excel. WAJIB jika tipe_klien=RESTO/B2C (sekaligus jadi PIC AR Client). Jika kosong, pic_ar dipakai sebagai pengganti (berlaku untuk PT/B2B maupun RESTO/B2C). Jika nama_pic & pic_ar sama-sama diisi tapi menunjuk karyawan berbeda, baris ditolak.', 'Ya (RESTO/B2C)', 'Andi Wijaya / 3273010101900001'],
            ['supervisor',      'Nama supervisor Resto',                                           'Opsional',               'Budi Supervisor'],
            ['no_hp_supervisor','Nomor HP supervisor',                                             'Opsional',               '08111222333'],
            ['stokis',          'Nama stokis Resto',                                               'Opsional',               'Stokis Utama'],
            ['area',            'Area/wilayah Resto',                                              'Opsional',               'Jakarta Pusat'],
            ['kota',            'Kota Resto',                                                      'Opsional',               'Jakarta'],
            ['alamat',          'Alamat lengkap Resto',                                            'Opsional',               'Jl. Sudirman No. 1'],
            ['no_telp',         'Nomor telepon Resto',                                             'Opsional',               '02112345678'],
            ['tgl_aktif',       'Tanggal aktif Resto (format: DD-MM-YYYY)',                        'Opsional',               '01-01-2026'],
            ['keterangan',      'Keterangan tambahan Resto',                                       'Opsional',               'Gerai pusat'],
            ['pic_ar',          'Nama karyawan AR — boleh diisi NAMA karyawan, NIK, atau kombinasi "Nama (NIK)"/"Nama - NIK"/"Nama / NIK" (lookup). Kombinasi memakai NIK sebagai identitas utama — kalau nama di Excel beda dari Master Karyawan, nama akan ditimpa mengikuti Excel. WAJIB jika tipe_klien=PT/B2B (jadi PIC AR Client). Untuk tipe_klien=RESTO/B2C bersifat opsional (hanya dipakai sebagai pengganti jika nama_pic kosong). NPWP & kontak Client AR otomatis dari Investor baris ini (npwp → no_npwp, no_hp → no_wa) untuk semua tipe.', 'Ya (PT/B2B)', 'Siti Rahayu / 3273010101900002'],
            ['tipe_klien',      'Tipe Client AR: RESTO/B2C atau PT/B2B (RESTO=B2C, PT=B2B — boleh isi salah satu penulisan) — kosongkan jika tidak membuat Klien', 'Ya (untuk Klien)',   'RESTO/B2C'],
            ['status',          '1 = Aktif (default), 0 = Nonaktif',                              'Opsional',               '1'],
        ];

        foreach ($masterDataCols as $i => [$col, $desc, $req, $ex]) {
            $bg = $i % 2 === 0 ? 'FFFFFFFF' : 'FFF5F5F5';
            $sheet->setCellValue("A{$row}", $col);
            $sheet->setCellValue("B{$row}", $desc);
            $sheet->setCellValue("C{$row}", $req);
            $sheet->setCellValue("D{$row}", $ex);
            $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
                'font'      => ['size' => 9],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(18);
            $row++;
        }

        $row += 2;

        // ─── Deskripsi Kolom Sheet MASTER BARANG
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", '  DESKRIPSI KOLOM — Sheet MASTER BARANG');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF388E3C']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++;

        foreach (['A' => 'Kolom', 'B' => 'Keterangan', 'C' => 'Wajib', 'D' => 'Contoh'] as $col => $label) {
            $sheet->setCellValue("{$col}{$row}", $label);
        }
        $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF66BB6A']],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(20);
        $row++;

        $barangCols = [
            ['kode_barang', 'Kode barang (uppercase) — wajib untuk barang baru; tidak diupdate', 'Ya (barang baru)', 'BRG-001'],
            ['nama_barang', 'Nama barang (upsert key, case-insensitive)',                         'Ya',              'Produk A'],
            ['spesifikasi', 'Deskripsi spesifikasi produk',                                       'Opsional',        '500ml, warna biru'],
            ['keterangan',  'Keterangan tambahan',                                                'Opsional',        'Stok prioritas'],
            ['status',      '1 = Aktif (default), 0 = Nonaktif',                                 'Opsional',        '1'],
        ];

        foreach ($barangCols as $i => [$col, $desc, $req, $ex]) {
            $bg = $i % 2 === 0 ? 'FFFFFFFF' : 'FFF5F5F5';
            $sheet->setCellValue("A{$row}", $col);
            $sheet->setCellValue("B{$row}", $desc);
            $sheet->setCellValue("C{$row}", $req);
            $sheet->setCellValue("D{$row}", $ex);
            $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
                'font'      => ['size' => 9],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(18);
            $row++;
        }
    }
}
