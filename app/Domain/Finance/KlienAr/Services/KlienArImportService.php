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
                'message' => 'Header kolom "kode_klien" tidak ditemukan dalam file. Pastikan menggunakan template import yang disediakan dan tidak mengubah nama kolom pada baris header.',
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

                $namaKlien      = trim((string) ($row[1] ?? ''));
                $tipeKlien      = strtoupper(trim((string) ($row[2] ?? '')));
                $namaResto      = $this->importValue($row[3] ?? '');
                $namaKaryawanAr = $this->importValue($row[4] ?? '');
                $namaEntitas    = $this->importValue($row[8] ?? '');

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
                    'kode_klien'     => $this->importValue($row[0] ?? ''),
                    'nama_klien'     => $namaKlien,
                    'tipe_klien'     => $tipeKlien,
                    'resto_id'       => $restoId,
                    'karyawan_ar_id' => $karyawanArId,
                    'perusahaan_id'  => $perusahaanId,
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
                    'perusahaan_id'  => ['nullable', 'integer'],
                    'no_npwp'        => ['nullable', 'string', 'max:30'],
                    'no_wa'          => ['nullable', 'string', 'max:20'],
                    'status'         => ['nullable', 'boolean'],
                ]);

                if ($validator->fails()) {
                    $errors[] = ['row' => $lineNumber, 'message' => implode('; ', $validator->errors()->all())];
                    continue;
                }

                // Upsert: cari berdasarkan kode_klien, fallback ke nama_klien + tipe_klien
                $existing = KlienAr::where('kode_klien', $data['kode_klien'])->latest()->first();
                if (!$existing) {
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
}
