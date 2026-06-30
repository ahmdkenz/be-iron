<?php

namespace App\Domain\Finance\KlienAr\Services;

use App\Domain\Finance\KlienAr\DTO\KlienArDTO;
use App\Models\KlienAr;
use App\Models\KlienArImportBatch;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\Resto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Memproses import data KlienAr dari file CSV/XLSX di dalam queue job.
 *
 * Dioptimasi untuk ribuan baris:
 *  - Preload map Resto, Karyawan, dan Perusahaan ke memori (hindari query per-baris)
 *  - Commit bertahap per CHUNK baris (bukan satu transaksi raksasa)
 *  - Update progress ke KlienArImportBatch agar bisa dipantau frontend
 *
 * Perilaku upsert dipertahankan: kode_klien yang sudah ada akan DIPERBARUI,
 * sisanya DITAMBAHKAN — sama dengan implementasi sinkron sebelumnya.
 */
class KlienArImportService
{
    /** Jumlah baris per commit transaksi. */
    private const CHUNK = 100;

    public function __construct(private readonly KlienArService $service) {}

    public function process(KlienArImportBatch $batch): void
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
                'message' => 'Header kolom "nama_klien" tidak ditemukan dalam file. Pastikan menggunakan template import yang disediakan dan tidak mengubah nama kolom pada baris header.',
            ]);
            return;
        }

        $batch->update([
            'status' => 'processing',
            'total'  => count($rows),
        ]);

        // Preload master data ke memori untuk hindari N+1 query per baris.
        $restoMap      = Resto::pluck('id', 'nama_resto')->all();
        $karyawanMap   = Karyawan::pluck('id', 'nama_karyawan')->all();
        $perusahaanMap = Perusahaan::pluck('id', 'nama_perusahaan')->all();

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

                $namaKlien      = trim((string) ($row[0] ?? ''));
                $tipeKlien      = strtoupper(trim((string) ($row[1] ?? '')));
                $namaResto      = $this->importValue($row[2] ?? '');
                $namaKaryawanAr = $this->importValue($row[3] ?? '');
                $namaEntitas    = $this->importValue($row[7] ?? '');

                // Resolve Resto
                $restoId = null;
                if ($namaResto) {
                    $restoId = $restoMap[$namaResto] ?? null;
                    if (!$restoId) {
                        $errors[] = ['row' => $lineNumber, 'message' => "Resto '{$namaResto}' tidak ditemukan di sistem."];
                        continue;
                    }
                } elseif ($tipeKlien === 'RESTO') {
                    $errors[] = ['row' => $lineNumber, 'message' => "Kolom nama_resto wajib diisi untuk tipe klien {$tipeKlien}."];
                    continue;
                }

                // Auto-lookup nama investor dari resto jika nama_klien kosong (B2C)
                if ($tipeKlien === 'RESTO' && $restoId && $namaKlien === '') {
                    $restoObj  = Resto::with('investor')->find($restoId);
                    $namaKlien = $restoObj?->investor?->nama_investor ?? '';
                    if ($namaKlien === '') {
                        $errors[] = ['row' => $lineNumber, 'message' => "Kolom nama_klien kosong dan resto '{$namaResto}' tidak memiliki investor — nama_klien tidak dapat ditentukan otomatis."];
                        continue;
                    }
                }

                // Resolve Karyawan AR
                $karyawanArId = null;
                if ($namaKaryawanAr) {
                    $karyawanArId = $karyawanMap[$namaKaryawanAr] ?? null;
                    if (!$karyawanArId) {
                        $errors[] = ['row' => $lineNumber, 'message' => "Karyawan AR '{$namaKaryawanAr}' tidak ditemukan di sistem."];
                        continue;
                    }
                }

                // Resolve Perusahaan (opsional, eksplisit untuk PT)
                $perusahaanId = null;
                if ($namaEntitas) {
                    $perusahaanId = $perusahaanMap[$namaEntitas] ?? null;
                    if (!$perusahaanId) {
                        $errors[] = ['row' => $lineNumber, 'message' => "Entitas '{$namaEntitas}' tidak ditemukan di sistem."];
                        continue;
                    }
                }

                $data = [
                    'nama_klien'     => $namaKlien,
                    'tipe_klien'     => $tipeKlien,
                    'resto_id'       => $restoId,
                    'karyawan_ar_id' => $karyawanArId,
                    'perusahaan_id'  => $perusahaanId,
                    'no_npwp'        => $this->importValue($row[4] ?? ''),
                    'no_wa'          => $this->importValue($row[5] ?? ''),
                    'status'         => isset($row[6]) && trim((string) $row[6]) !== '' ? (bool) (int) $row[6] : true,
                ];

                $validator = Validator::make($data, [
                    'nama_klien'     => ['required', 'string', 'max:150'],
                    'tipe_klien'     => ['required', 'in:PT,RESTO'],
                    'resto_id'       => ['nullable', 'integer'],
                    'karyawan_ar_id' => ['required', 'integer'],
                    'perusahaan_id'  => ['nullable', 'integer'],
                    'no_npwp'        => ['nullable', 'string', 'max:30'],
                    'no_wa'          => ['nullable', 'string', 'max:20'],
                    'status'         => ['nullable', 'boolean'],
                ]);

                if ($validator->fails()) {
                    $errors[] = ['row' => $lineNumber, 'message' => implode('; ', $validator->errors()->all())];
                    continue;
                }

                // Upsert: B2B dedup by perusahaan_id (1 PT = 1 Client), B2C by nama_klien+tipe_klien
                if ($tipeKlien === 'PT' && !empty($data['perusahaan_id'])) {
                    $existing = KlienAr::where('perusahaan_id', $data['perusahaan_id'])
                        ->where('tipe_klien', 'PT')
                        ->latest()
                        ->first();
                } else {
                    $existing = KlienAr::where('nama_klien', $data['nama_klien'])
                        ->where('tipe_klien', $data['tipe_klien'])
                        ->latest()
                        ->first();
                }

                try {
                    if ($existing) {
                        $this->service->update($existing, KlienArDTO::fromRequest($data));
                        $updatedCount++;
                    } else {
                        $this->service->create(KlienArDTO::fromRequest($data));
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

    private function flushProgress(KlienArImportBatch $batch, int $processed, int $inserted, int $updated, array $errors): void
    {
        $batch->update([
            'processed' => min($processed, $batch->total),
            'inserted'  => $inserted,
            'updated'   => $updated,
            'failed'    => count($errors),
        ]);
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
        $isOldFormat = false;

        foreach ($sheet->getRowIterator() as $rowObj) {
            $cellIter = $rowObj->getCellIterator();
            $cellIter->setIterateOnlyExistingCells(false);

            $cells = [];
            foreach ($cellIter as $cell) {
                $cells[] = $this->xlsxCellToString($cell);
            }

            $firstCell = strtolower(trim($cells[0] ?? ''));

            if (!$headerFound) {
                if ($firstCell === 'nama_klien') {
                    $headerFound = true;
                    $isOldFormat = false;
                    $rows[]      = array_slice($cells, 0, 8);
                } elseif ($firstCell === 'kode_klien') {
                    // Format lama: strip kolom kode_klien, shift sisa kolom ke kiri
                    $headerFound = true;
                    $isOldFormat = true;
                    $rows[]      = array_slice($cells, 1, 8);
                }
                continue;
            }

            $rows[] = $isOldFormat
                ? array_slice($cells, 1, 8)
                : array_slice($cells, 0, 8);
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
}
