<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Parsers;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

abstract class AbstractBankParser implements BankParserInterface
{
    // ── XLSX loader ───────────────────────────────────────────────────

    protected function loadXlsx(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        // formatData=false → nilai mentah (int/float/string) tanpa format locale.
        // Menghindari ambiguitas "1,413,590.00" vs "1.413.590,00" dari locale Excel.
        return $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
    }

    // ── Header detection (scoring) ────────────────────────────────────

    /**
     * Scan semua baris, beri skor berdasarkan berapa banyak canonical field
     * yang ditemukan di baris tersebut. Baris dengan skor tertinggi (min $minScore)
     * dipilih sebagai header row.
     *
     * Return: ['rowIdx' => int, 'colMap' => ['tanggal' => colIdx, ...]]
     * rowIdx = -1 jika tidak ditemukan.
     */
    protected function detectHeaderRow(array $data, int $minScore = 3): array
    {
        $keywords = BankStatementSchema::FIELD_KEYWORDS;

        $bestScore  = 0;
        $bestRowIdx = -1;
        $bestColMap = [];

        foreach ($data as $rowIdx => $row) {
            $colMap = [];
            foreach ($row as $colIdx => $cell) {
                $cell = strtolower(trim((string) $cell));
                if ($cell === '') continue;

                foreach ($keywords as $field => $variants) {
                    if (isset($colMap[$field])) continue;
                    foreach ($variants as $kw) {
                        if (str_contains($cell, $kw)) {
                            $colMap[$field] = $colIdx;
                            break;
                        }
                    }
                }
            }

            $score = count($colMap);
            if ($score > $bestScore) {
                $bestScore  = $score;
                $bestRowIdx = $rowIdx;
                $bestColMap = $colMap;
            }
        }

        if ($bestScore < $minScore) {
            return ['rowIdx' => -1, 'colMap' => []];
        }

        return ['rowIdx' => $bestRowIdx, 'colMap' => $bestColMap];
    }

    // ── Shared helpers ────────────────────────────────────────────────

    protected function parseTanggal(string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') return null;

        // Excel serial number (dari loadXlsx dengan formatData=false)
        // Nilai valid: 1 (1 Jan 1900) s/d ~2.958.465 (31 Des 9999)
        if (is_numeric($raw) && (float) $raw > 1 && (float) $raw < 2_958_466) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            } catch (\Exception) {}
        }

        // Coba format teks (CSV atau sel teks di XLSX)
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd M Y', 'd-M-Y', 'd F Y', 'Y/m/d'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $raw)->format('Y-m-d');
            } catch (\Exception) {}
        }

        return null;
    }

    protected function parseAngka(string $raw): float
    {
        $raw = trim((string) $raw);
        if ($raw === '') return 0.0;

        // Nilai numerik murni dari PhpSpreadsheet (formatData=false) atau CSV angka biasa.
        // Contoh: "1413590", "1413590.5", "-500.25" — langsung konversi.
        if (is_numeric($raw)) return (float) $raw;

        // Hapus semua karakter selain digit, titik, koma, dan tanda minus
        $clean = preg_replace('/[^\d,\.\-]/', '', $raw);
        if ($clean === '' || $clean === '-') return 0.0;

        // Format Indonesia: "1.234.567,89" — koma sebagai pemisah desimal
        // Ciri: diakhiri koma lalu 1-2 digit
        if (preg_match('/,\d{1,2}$/', $clean)) {
            $clean = str_replace('.', '', $clean);
            return (float) str_replace(',', '.', $clean);
        }

        // Format US/Internasional: "1,234,567.89" — titik sebagai pemisah desimal
        // Ciri: diakhiri titik lalu 1-2 digit DAN ada koma di bagian ribuan
        if (preg_match('/\.\d{1,2}$/', $clean) && str_contains($clean, ',')) {
            return (float) str_replace(',', '', $clean);
        }

        // Tanpa pemisah desimal eksplisit — hapus semua pemisah ribuan
        // Contoh: "1.413.590" (ID), "1,413,590" (US), "1413590"
        return (float) str_replace(['.', ','], '', $clean);
    }
}
