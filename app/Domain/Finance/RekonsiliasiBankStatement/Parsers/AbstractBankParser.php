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
        return $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
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
        $raw = trim($raw);
        if ($raw === '') return null;

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd M Y', 'd-M-Y', 'd F Y', 'Y/m/d'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $raw)->format('Y-m-d');
            } catch (\Exception) {}
        }

        if (is_numeric($raw)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            } catch (\Exception) {}
        }

        return null;
    }

    protected function parseAngka(string $raw): float
    {
        $clean = preg_replace('/[^\d,\.]/', '', trim($raw));

        // Format "1.234.567,89" — koma sebagai desimal
        if (preg_match('/,\d{1,2}$/', $clean)) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        }

        return (float) $clean;
    }
}
