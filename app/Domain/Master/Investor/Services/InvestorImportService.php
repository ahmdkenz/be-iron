<?php

namespace App\Domain\Master\Investor\Services;

use App\Domain\Master\Investor\DTO\InvestorDTO;
use App\Models\Investor;
use App\Models\InvestorImportBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as PhpSpreadsheetDate;

/**
 * Memproses import data Investor dari file CSV/XLSX di dalam queue job.
 *
 * Dioptimasi untuk ribuan baris:
 *  - Preload set KTP & NPWP yang sudah ada ke memori (hindari query unique per-baris),
 *    sekaligus mendeteksi duplikat antar baris dalam file yang sama.
 *  - Commit bertahap per CHUNK baris (bukan satu transaksi raksasa)
 *  - Update progress ke InvestorImportBatch agar bisa dipantau frontend
 *
 * Perilaku upsert dipertahankan: nama_investor yang sudah ada akan DIPERBARUI,
 * sisanya DITAMBAHKAN — sama dengan implementasi sinkron sebelumnya di InvestorController.
 */
class InvestorImportService
{
    /** Jumlah baris per commit transaksi. */
    private const CHUNK = 100;

    public function __construct(private readonly InvestorService $service) {}

    public function process(InvestorImportBatch $batch): void
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
                'message' => 'Header kolom "nama_investor" tidak ditemukan dalam file. Pastikan menggunakan template import yang disediakan dan tidak mengubah nama kolom pada baris header.',
            ]);
            return;
        }

        $batch->update([
            'status' => 'processing',
            'total'  => count($rows),
        ]);

        // Preload KTP/NPWP yang sudah ada (nilai => owner investor id) untuk cek
        // keunikan di memori. Map ini juga di-update saat baris baru tersimpan agar
        // duplikat dalam file yang sama tetap terdeteksi pada baris berikutnya.
        [$ktpMap, $npwpMap] = $this->buildUniqueMaps();

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

                $data = [
                    'nama_investor'   => trim((string) ($row[0] ?? '')),
                    'ktp'             => $this->importValue($row[1] ?? ''),
                    'npwp'            => $this->importValue($row[2] ?? ''),
                    'no_hp'           => $this->importValue($row[3] ?? ''),
                    'pengelola'       => $this->importValue($row[4] ?? ''),
                    'no_hp_pengelola' => $this->importValue($row[5] ?? ''),
                    'kode_cabang'     => $this->importValue($row[6] ?? ''),
                    'id_cabang'       => $this->importValue($row[7] ?? ''),
                    'status'          => isset($row[8]) && trim((string) $row[8]) !== '' ? (bool) (int) $row[8] : true,
                ];

                $validator = Validator::make($data, [
                    'nama_investor'   => ['required', 'string', 'max:150'],
                    'ktp'             => ['nullable', 'string', 'max:20'],
                    'npwp'            => ['nullable', 'string', 'max:20'],
                    'no_hp'           => ['nullable', 'string', 'max:20'],
                    'pengelola'       => ['nullable', 'string', 'max:150'],
                    'no_hp_pengelola' => ['nullable', 'string', 'max:20'],
                    'kode_cabang'     => ['nullable', 'string', 'max:50'],
                    'id_cabang'       => ['nullable', 'string', 'max:50'],
                    'status'          => ['nullable', 'boolean'],
                ]);

                if ($validator->fails()) {
                    $errors[] = ['row' => $lineNumber, 'message' => implode('; ', $validator->errors()->all())];
                    continue;
                }

                // Cocokkan per kode_cabang (penanda unik per outlet/cabang), bukan
                // per nama_investor — satu orang bisa punya banyak outlet sehingga
                // pencocokan by nama akan menggabungkan semuanya jadi satu record.
                // Baris tanpa kode_cabang selalu di-insert sebagai data baru.
                $kodeCabang = $data['kode_cabang'];
                $existing   = $kodeCabang !== null
                    ? Investor::where('kode_cabang', $kodeCabang)->latest()->first()
                    : null;
                $existingId = $existing?->id;

                // Keunikan KTP/NPWP — terhadap data lama (DB) maupun baris lain dalam file.
                $rowErrors = [];
                $ktp  = $data['ktp'];
                $npwp = $data['npwp'];
                if ($ktp !== null) {
                    $owner = $ktpMap[$ktp] ?? null;
                    if ($owner !== null && $owner !== $existingId) {
                        $rowErrors[] = "KTP '{$ktp}' sudah terdaftar pada investor lain";
                    }
                }
                if ($npwp !== null) {
                    $owner = $npwpMap[$npwp] ?? null;
                    if ($owner !== null && $owner !== $existingId) {
                        $rowErrors[] = "NPWP '{$npwp}' sudah terdaftar pada investor lain";
                    }
                }
                if (!empty($rowErrors)) {
                    $errors[] = ['row' => $lineNumber, 'message' => implode('; ', $rowErrors)];
                    continue;
                }

                try {
                    if ($existing) {
                        $investor = $this->service->update($existing, InvestorDTO::fromRequest($data));
                        $updatedCount++;
                    } else {
                        $investor = $this->service->create(InvestorDTO::fromRequest($data));
                        $insertedCount++;
                    }

                    // Catat KTP/NPWP yang baru dipakai agar duplikat dalam file terdeteksi.
                    if ($ktp !== null)  $ktpMap[$ktp]   = $investor->id;
                    if ($npwp !== null) $npwpMap[$npwp] = $investor->id;
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

    private function flushProgress(InvestorImportBatch $batch, int $processed, int $inserted, int $updated, array $errors): void
    {
        $batch->update([
            'processed' => min($processed, $batch->total),
            'inserted'  => $inserted,
            'updated'   => $updated,
            'failed'    => count($errors),
        ]);
    }

    /**
     * @return array{0: array<string,int>, 1: array<string,int>} [ktp => id, npwp => id]
     */
    private function buildUniqueMaps(): array
    {
        $ktpMap  = [];
        $npwpMap = [];
        foreach (Investor::all(['id', 'ktp', 'npwp']) as $inv) {
            if ($inv->ktp !== null && $inv->ktp !== '' && !isset($ktpMap[$inv->ktp])) {
                $ktpMap[$inv->ktp] = $inv->id;
            }
            if ($inv->npwp !== null && $inv->npwp !== '' && !isset($npwpMap[$inv->npwp])) {
                $npwpMap[$inv->npwp] = $inv->id;
            }
        }
        return [$ktpMap, $npwpMap];
    }

    // ──────────────────────────────────────────────────────────────
    //  Parsing (dipindahkan dari InvestorController)
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
}
