<?php

namespace App\Domain\Master\Barang\Services;

use App\Models\Barang;
use App\Models\BarangImportBatch;
use App\Models\Brand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as PhpSpreadsheetDate;

/**
 * Memproses import data Barang dari file CSV/XLSX di dalam queue job.
 *
 * Dioptimasi untuk ribuan baris:
 *  - Preload brand map ke memori (hindari query per-baris)
 *  - Preload kode_barang yang sudah ada untuk cek unique in-memory
 *  - Commit bertahap per CHUNK baris (bukan satu transaksi raksasa)
 *  - Update progress ke BarangImportBatch agar bisa dipantau frontend
 *
 * Upsert: lookup by nama_barang (case-insensitive).
 *  - Ada  → UPDATE (kode_barang tidak diperbarui)
 *  - Baru → CREATE (kode_barang wajib, unik)
 */
class BarangImportService
{
    /** Jumlah baris per commit transaksi. */
    private const CHUNK = 100;

    public function process(BarangImportBatch $batch): void
    {
        $disk = Storage::disk('local');
        if (!$batch->file_path || !$disk->exists($batch->file_path)) {
            throw new \RuntimeException("File import tidak ditemukan: {$batch->file_path}");
        }

        $fullPath = $disk->path($batch->file_path);
        $ext      = strtolower(pathinfo($batch->file_path, PATHINFO_EXTENSION));
        $isXlsx   = in_array($ext, ['xlsx', 'xls']);

        $rows = $isXlsx ? $this->parseXlsx($fullPath) : $this->parseCsv($fullPath);

        if ($isXlsx && empty($rows)) {
            $batch->update([
                'status'  => 'failed',
                'message' => 'Header kolom "nama_barang" tidak ditemukan dalam file. Pastikan menggunakan template import yang disediakan dan tidak mengubah nama kolom pada baris header.',
            ]);
            return;
        }

        $batch->update([
            'status' => 'processing',
            'total'  => count($rows),
        ]);

        // Preload brand map: lowercase(nama_brand) → brand_id
        $brandMap = Brand::all(['id', 'nama_brand'])
            ->mapWithKeys(fn($b) => [strtolower($b->nama_brand) => $b->id])
            ->all();

        // Preload kode_barang yang sudah ada (uppercase) → barang_id
        // Dipakai untuk: (a) cek unique saat CREATE, (b) deteksi duplikat dalam file.
        $kodeMap = Barang::all(['id', 'kode_barang'])
            ->mapWithKeys(fn($b) => [strtoupper($b->kode_barang) => $b->id])
            ->all();

        // Track kode_barang baru yang ditambahkan dalam file yang sama
        $newKodeSeen = [];

        $insertedCount = 0;
        $updatedCount  = 0;
        $totalData     = 0;
        $errors        = [];
        $processed     = 0;
        $lineNumber    = 0;
        $headerSkipped = false;
        $inChunk       = 0;

        DB::beginTransaction();
        try {
            foreach ($rows as $row) {
                if ($inChunk >= self::CHUNK) {
                    DB::commit();
                    $this->flushProgress($batch, $processed, $insertedCount, $updatedCount, $errors);
                    DB::beginTransaction();
                    $inChunk = 0;
                }
                $lineNumber++;
                $processed++;
                $inChunk++;

                $firstCell = trim((string) ($row[0] ?? ''));
                if (str_starts_with($firstCell, '#')) continue;
                if (!$headerSkipped) { $headerSkipped = true; continue; }
                if (str_starts_with($firstCell, '[CONTOH]')) continue;
                if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) continue;

                $totalData++;

                $rawKode   = strtoupper(trim((string) ($row[0] ?? '')));
                $rawNama   = trim((string) ($row[1] ?? ''));
                $rawStatus = trim((string) ($row[5] ?? ''));

                $data = [
                    'kode_barang' => $rawKode === '' ? null : $rawKode,
                    'nama_barang' => $rawNama,
                    'spesifikasi' => $this->importValue($row[2] ?? ''),
                    'nama_brand'  => $this->importValue($row[3] ?? ''),
                    'keterangan'  => $this->importValue($row[4] ?? ''),
                    'status'      => $this->parseStatus($rawStatus),
                ];

                // Cari existing berdasarkan nama_barang (case-insensitive)
                $existing = Barang::whereRaw('LOWER(nama_barang) = ?', [strtolower($rawNama)])->first();

                // Validasi dasar
                $rules = [
                    'nama_barang' => ['required', 'string', 'max:150'],
                    'spesifikasi' => ['nullable', 'string'],
                    'keterangan'  => ['nullable', 'string'],
                    'status'      => ['nullable', 'boolean'],
                ];

                if (!$existing) {
                    // Data baru: kode_barang wajib
                    $rules['kode_barang'] = ['required', 'string', 'max:50'];
                }

                $validator = Validator::make($data, $rules);
                if ($validator->fails()) {
                    $errors[] = ['row' => $lineNumber, 'message' => implode('; ', $validator->errors()->all())];
                    continue;
                }

                // Cek unique kode_barang untuk data baru
                if (!$existing) {
                    $kodeUpper = strtoupper($data['kode_barang']);
                    if (isset($kodeMap[$kodeUpper]) || isset($newKodeSeen[$kodeUpper])) {
                        $errors[] = ['row' => $lineNumber, 'message' => "kode_barang '{$kodeUpper}' sudah digunakan oleh barang lain."];
                        continue;
                    }
                }

                // Resolve brand_id
                $brandId = null;
                if ($data['nama_brand'] !== null) {
                    $brandId = $brandMap[strtolower($data['nama_brand'])] ?? null;
                    if ($brandId === null) {
                        $errors[] = ['row' => $lineNumber, 'message' => "Brand '{$data['nama_brand']}' tidak ditemukan di sistem."];
                        continue;
                    }
                }

                try {
                    if ($existing) {
                        $existing->update([
                            'nama_barang' => $data['nama_barang'],
                            'spesifikasi' => $data['spesifikasi'],
                            'brand_id'    => $brandId,
                            'keterangan'  => $data['keterangan'],
                            'status'      => $data['status'] ?? true,
                        ]);
                        $updatedCount++;
                    } else {
                        $kodeUpper = strtoupper($data['kode_barang']);
                        $barang    = Barang::create([
                            'kode_barang' => $kodeUpper,
                            'nama_barang' => $data['nama_barang'],
                            'spesifikasi' => $data['spesifikasi'],
                            'brand_id'    => $brandId,
                            'keterangan'  => $data['keterangan'],
                            'status'      => $data['status'] ?? true,
                        ]);
                        // Catat kode baru agar duplikat dalam file terdeteksi
                        $newKodeSeen[$kodeUpper] = $barang->id;
                        $kodeMap[$kodeUpper]     = $barang->id;
                        $insertedCount++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = ['row' => $lineNumber, 'message' => 'Gagal menyimpan: ' . $e->getMessage()];
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();
            throw $e;
        }

        $failed = max(0, $totalData - $insertedCount - $updatedCount);
        $batch->update([
            'status'     => 'completed',
            'processed'  => $batch->total,
            'total_data' => $totalData,
            'inserted'   => $insertedCount,
            'updated'    => $updatedCount,
            'failed'     => $failed,
            'errors'     => $errors,
            'message'    => "Import selesai. {$insertedCount} ditambahkan, {$updatedCount} diperbarui, {$failed} gagal.",
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    private function flushProgress(BarangImportBatch $batch, int $processed, int $inserted, int $updated, array $errors): void
    {
        $batch->update([
            'processed' => min($processed, $batch->total),
            'inserted'  => $inserted,
            'updated'   => $updated,
            'failed'    => count($errors),
        ]);
    }

    /**
     * Parse nilai status dari string ke boolean.
     * Terima: 'aktif', '1', 'true' → true; 'nonaktif', '0', 'false' → false; '' → true (default).
     */
    private function parseStatus(string $raw): bool
    {
        if ($raw === '') return true;
        return in_array(strtolower($raw), ['aktif', '1', 'true', 'yes', 'ya']);
    }

    private function importValue(mixed $val): ?string
    {
        $s = trim((string) $val);
        return ($s === '' || $s === '-') ? null : $s;
    }

    // ──────────────────────────────────────────────────────────────
    //  Parsing
    // ──────────────────────────────────────────────────────────────

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

            $cells     = array_slice($cells, 0, 6);
            $firstCell = trim($cells[0] ?? '');

            if (!$headerFound) {
                // Header ada di kolom B (index 1) = 'nama_barang', tapi kita detect kolom A = 'kode_barang'
                // atau kolom B = 'nama_barang'
                if (strtolower($firstCell) === 'kode_barang') {
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
            if (PhpSpreadsheetDate::isDateTime($cell)) {
                return PhpSpreadsheetDate::excelToDateTimeObject($value)->format('d-m-Y');
            }
            return fmod($value, 1.0) === 0.0
                ? sprintf('%.0f', $value)
                : (string) $value;
        }
        return trim((string) $value);
    }
}
