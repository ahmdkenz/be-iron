<?php

namespace App\Domain\Master\Resto\Services;

use App\Domain\Master\Resto\DTO\RestoDTO;
use App\Models\Brand;
use App\Models\Investor;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\Resto;
use App\Models\RestoImportBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as PhpSpreadsheetDate;

/**
 * Memproses import data Resto dari file CSV/XLSX di dalam queue job.
 *
 * Dioptimasi untuk ribuan baris:
 *  - Preload master (investor/entitas/brand/karyawan) ke memori (hindari 4 query lookup per-baris)
 *  - Commit bertahap per CHUNK baris (bukan satu transaksi raksasa)
 *  - Update progress ke RestoImportBatch agar bisa dipantau frontend
 *
 * Perilaku upsert dipertahankan: nama_resto yang sudah ada akan DIPERBARUI,
 * sisanya DITAMBAHKAN — sama dengan implementasi sinkron sebelumnya di RestoController.
 */
class RestoImportService
{
    /** Jumlah baris per commit transaksi. */
    private const CHUNK = 100;

    public function __construct(private readonly RestoService $service) {}

    public function process(RestoImportBatch $batch): void
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
                'message' => 'Header kolom "kode_resto" tidak ditemukan dalam file. Pastikan menggunakan template import yang disediakan dan tidak mengubah nama kolom pada baris header.',
            ]);
            return;
        }

        $batch->update([
            'status' => 'processing',
            'total'  => count($rows),
        ]);

        // Preload master untuk menghindari query per-baris.
        $investorMap   = $this->buildLowerMap(Investor::all(['id', 'nama_investor']), 'nama_investor');
        $brandMap      = $this->buildLowerMap(Brand::all(['id', 'nama_brand']), 'nama_brand');
        $karyawanMap   = $this->buildLowerMap(Karyawan::all(['id', 'nama_karyawan']), 'nama_karyawan');
        $perusahaanMap = $this->buildPerusahaanMap();

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

                $kodeResto      = $this->importValue($row[0] ?? '') ?? '';
                $namaResto      = trim((string) ($row[1] ?? ''));
                $namaInvestor   = $this->importValue($row[2] ?? '') ?? '';
                $namaPerusahaan = $this->importValue($row[3] ?? '') ?? '';
                $namaBrand      = $this->importValue($row[4] ?? '') ?? '';
                $namaPic        = $this->importValue($row[5] ?? '') ?? '';

                $investorId   = null;
                $perusahaanId = null;
                $brandId      = null;
                $karyawanId   = null;
                $rowErrors    = [];

                if ($namaInvestor) {
                    $investorId = $investorMap[strtolower($namaInvestor)] ?? null;
                    if (!$investorId) $rowErrors[] = "Investor '{$namaInvestor}' tidak ditemukan";
                }
                if ($namaPerusahaan) {
                    $perusahaanId = $perusahaanMap[strtolower($namaPerusahaan)] ?? null;
                    if (!$perusahaanId) $rowErrors[] = "Entitas '{$namaPerusahaan}' tidak ditemukan";
                }
                if ($namaBrand) {
                    $brandId = $brandMap[strtolower($namaBrand)] ?? null;
                    if (!$brandId) $rowErrors[] = "Brand '{$namaBrand}' tidak ditemukan";
                }
                if ($namaPic) {
                    $karyawanId = $karyawanMap[strtolower($namaPic)] ?? null;
                    if (!$karyawanId) $rowErrors[] = "PIC (karyawan) '{$namaPic}' tidak ditemukan";
                }

                if (!empty($rowErrors)) {
                    $errors[] = ['row' => $lineNumber, 'message' => implode('; ', $rowErrors)];
                    continue;
                }

                $existing = Resto::where('nama_resto', $namaResto)->latest()->first();

                if (!$existing && $kodeResto === '') {
                    $errors[] = ['row' => $lineNumber, 'message' => "kode_resto wajib diisi untuk data baru '{$namaResto}'"];
                    continue;
                }

                $data = [
                    'nama_resto'       => $namaResto,
                    'kode_resto'       => $kodeResto ?: null,
                    'investor_id'      => $investorId,
                    'perusahaan_id'    => $perusahaanId,
                    'brand_id'         => $brandId,
                    'karyawan_id'      => $karyawanId,
                    'supervisor'       => $this->importValue($row[6] ?? ''),
                    'no_hp_supervisor' => $this->importValue($row[7] ?? ''),
                    'stokis'           => $this->importValue($row[8] ?? ''),
                    'area'             => $this->importValue($row[9] ?? ''),
                    'kota'             => $this->importValue($row[10] ?? ''),
                    'alamat'           => $this->importValue($row[11] ?? ''),
                    'no_telp'          => $this->importValue($row[12] ?? ''),
                    'tgl_aktif'        => $this->importDate($row[13] ?? ''),
                    'keterangan'       => $this->importValue($row[14] ?? ''),
                    'status'           => isset($row[15]) && trim((string) $row[15]) !== '' ? (bool) (int) $row[15] : true,
                ];

                $validator = Validator::make($data, [
                    'nama_resto'       => ['required', 'string', 'max:150'],
                    'kode_resto'       => ['nullable', 'string', 'max:100'],
                    'investor_id'      => ['nullable', 'integer'],
                    'perusahaan_id'    => ['nullable', 'integer'],
                    'brand_id'         => ['nullable', 'integer'],
                    'karyawan_id'      => ['nullable', 'integer'],
                    'supervisor'       => ['nullable', 'string', 'max:150'],
                    'no_hp_supervisor' => ['nullable', 'string', 'max:20'],
                    'stokis'           => ['nullable', 'string', 'max:150'],
                    'area'             => ['nullable', 'string', 'max:100'],
                    'kota'             => ['nullable', 'string', 'max:100'],
                    'alamat'           => ['nullable', 'string'],
                    'no_telp'          => ['nullable', 'string', 'max:20'],
                    'tgl_aktif'        => ['nullable', 'date'],
                    'keterangan'       => ['nullable', 'string'],
                    'status'           => ['nullable', 'boolean'],
                ]);

                if ($validator->fails()) {
                    $errors[] = ['row' => $lineNumber, 'message' => implode('; ', $validator->errors()->all())];
                    continue;
                }

                try {
                    if ($existing) {
                        $this->service->update($existing, RestoDTO::fromRequest($data));
                        $updatedCount++;
                    } else {
                        $this->service->create(RestoDTO::fromRequest($data));
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

    private function flushProgress(RestoImportBatch $batch, int $processed, int $inserted, int $updated, array $errors): void
    {
        $batch->update([
            'processed' => min($processed, $batch->total),
            'inserted'  => $inserted,
            'updated'   => $updated,
            'failed'    => count($errors),
        ]);
    }

    /** @return array<string,int> LOWER(field) => id (occurrence pertama). */
    private function buildLowerMap(iterable $collection, string $field): array
    {
        $map = [];
        foreach ($collection as $model) {
            $val = $model->{$field};
            if ($val !== null && $val !== '' && !isset($map[strtolower($val)])) {
                $map[strtolower($val)] = $model->id;
            }
        }
        return $map;
    }

    /** @return array<string,int> nama_perusahaan & nama_singkatan_perusahaan (lower) => id. */
    private function buildPerusahaanMap(): array
    {
        $map = [];
        foreach (Perusahaan::all(['id', 'nama_perusahaan', 'nama_singkatan_perusahaan']) as $p) {
            if ($p->nama_perusahaan && !isset($map[strtolower($p->nama_perusahaan)])) {
                $map[strtolower($p->nama_perusahaan)] = $p->id;
            }
            if ($p->nama_singkatan_perusahaan && !isset($map[strtolower($p->nama_singkatan_perusahaan)])) {
                $map[strtolower($p->nama_singkatan_perusahaan)] = $p->id;
            }
        }
        return $map;
    }

    // ──────────────────────────────────────────────────────────────
    //  Parsing (dipindahkan dari RestoController)
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

            $cells     = array_slice($cells, 0, 16);
            $firstCell = trim($cells[0] ?? '');

            if (!$headerFound) {
                if (strtolower($firstCell) === 'kode_resto') {
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
}
