<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Parsers;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parser untuk rekening koran BSI (Bank Syariah Indonesia).
 * Mendukung format CSV (BSI Mobile/BizNet) dan XLSX.
 *
 * Format CSV: Tanggal | Keterangan | Nominal Debet | Nominal Kredit | Saldo
 * Format XLSX: TANGGAL | KETERANGAN | DEBIT | KREDIT | SALDO
 */
class BsiParser implements BankParserInterface
{
    public function parse(string $filePath): array
    {
        return $this->isCsv($filePath)
            ? $this->parseCsv($filePath)
            : $this->parseXlsx($filePath);
    }

    private function isCsv(string $filePath): bool
    {
        return strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'csv';
    }

    // ── CSV path (BSI Mobile / BizNet export) ────────────────────────

    private function parseCsv(string $filePath): array
    {
        $content = file_get_contents($filePath);
        // Hapus BOM
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        // Konversi encoding jika bukan UTF-8
        if (!mb_detect_encoding($content, 'UTF-8', true)) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $content));

        // Deteksi delimiter: ';' atau ','
        $delimiter = $this->detectDelimiter($lines);

        // Cari baris header yang mengandung "tanggal"
        $headerLine = -1;
        $colMap     = [];
        foreach ($lines as $i => $line) {
            $lower = strtolower($line);
            if (str_contains($lower, 'tanggal') && (str_contains($lower, 'keterangan') || str_contains($lower, 'debet') || str_contains($lower, 'debit'))) {
                $headerLine = $i;
                $cols = str_getcsv($line, $delimiter, '"');
                foreach ($cols as $idx => $col) {
                    $h = strtolower(trim($col));
                    if (str_contains($h, 'tanggal') || $h === 'tgl') $colMap['tanggal'] = $colMap['tanggal'] ?? $idx;
                    if (str_contains($h, 'keterangan') || str_contains($h, 'uraian') || str_contains($h, 'deskripsi')) $colMap['keterangan'] = $colMap['keterangan'] ?? $idx;
                    if ((str_contains($h, 'debet') || str_contains($h, 'debit')) && !str_contains($h, 'kredit')) $colMap['debit'] = $colMap['debit'] ?? $idx;
                    if (str_contains($h, 'kredit') && !str_contains($h, 'debet') && !str_contains($h, 'debit')) $colMap['kredit'] = $colMap['kredit'] ?? $idx;
                    if ($h === 'saldo') $colMap['saldo'] = $colMap['saldo'] ?? $idx;
                }
                break;
            }
        }

        if ($headerLine === -1) {
            throw new \RuntimeException('Format CSV BSI tidak dikenali: baris header tidak ditemukan.');
        }

        $rows = [];
        for ($i = $headerLine + 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;

            $cols = str_getcsv($line, $delimiter, '"');

            $tanggalRaw = trim($cols[$colMap['tanggal'] ?? -1] ?? '');
            if (empty($tanggalRaw)) continue;

            $tanggal = $this->parseTanggal($tanggalRaw);
            if (!$tanggal) continue;

            $keterangan = trim($cols[$colMap['keterangan'] ?? -1] ?? '');
            $debit      = $this->parseAngka($cols[$colMap['debit']  ?? -1] ?? '0');
            $kredit     = $this->parseAngka($cols[$colMap['kredit'] ?? -1] ?? '0');
            $saldo      = $this->parseAngka($cols[$colMap['saldo']  ?? -1] ?? '0');

            if ($debit == 0 && $kredit == 0) continue;

            $rows[] = compact('tanggal', 'keterangan', 'debit', 'kredit', 'saldo');
        }

        return $rows;
    }

    private function detectDelimiter(array $lines): string
    {
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            $semicolons = substr_count($line, ';');
            $commas     = substr_count($line, ',');
            return $semicolons > $commas ? ';' : ',';
        }
        return ',';
    }

    // ── XLSX path ────────────────────────────────────────────────────

    private function parseXlsx(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet       = $spreadsheet->getActiveSheet();
        $data        = $sheet->toArray(null, true, true, false);

        $headerRow = -1;
        foreach ($data as $rowIdx => $row) {
            foreach ($row as $cell) {
                $cellStr = strtolower(trim((string) $cell));
                if ($cellStr === 'tanggal' || $cellStr === 'tgl') {
                    $headerRow = $rowIdx;
                    break 2;
                }
            }
        }

        if ($headerRow === -1) {
            foreach ($data as $rowIdx => $row) {
                $rowText = strtolower(implode(' ', array_map('strval', $row)));
                if (str_contains($rowText, 'tanggal') && str_contains($rowText, 'keterangan')) {
                    $headerRow = $rowIdx;
                    break;
                }
            }
        }

        if ($headerRow === -1) {
            throw new \RuntimeException('Format file BSI tidak dikenali: baris header tidak ditemukan.');
        }

        $headers = $data[$headerRow];
        $colMap  = [];
        foreach ($headers as $colIdx => $header) {
            $h = strtolower(trim((string) $header));
            if ($h === 'tanggal' || $h === 'tgl') $colMap['tanggal'] = $colMap['tanggal'] ?? $colIdx;
            if (str_contains($h, 'keterangan') || str_contains($h, 'uraian') || str_contains($h, 'deskripsi')) $colMap['keterangan'] = $colMap['keterangan'] ?? $colIdx;
            if ((str_contains($h, 'debet') || str_contains($h, 'debit')) && !str_contains($h, 'kredit')) $colMap['debit'] = $colMap['debit'] ?? $colIdx;
            if (str_contains($h, 'kredit') && !str_contains($h, 'debet') && !str_contains($h, 'debit')) $colMap['kredit'] = $colMap['kredit'] ?? $colIdx;
            if ($h === 'saldo') $colMap['saldo'] = $colMap['saldo'] ?? $colIdx;
        }

        $rows = [];
        for ($i = $headerRow + 1; $i < count($data); $i++) {
            $row = $data[$i];

            $tanggalRaw = trim((string) ($row[$colMap['tanggal'] ?? -1] ?? ''));
            if (empty($tanggalRaw)) continue;

            $tanggal = $this->parseTanggal($tanggalRaw);
            if (!$tanggal) continue;

            $keterangan = trim((string) ($row[$colMap['keterangan'] ?? -1] ?? ''));
            $debit      = $this->parseAngka((string) ($row[$colMap['debit']  ?? -1] ?? '0'));
            $kredit     = $this->parseAngka((string) ($row[$colMap['kredit'] ?? -1] ?? '0'));
            $saldo      = $this->parseAngka((string) ($row[$colMap['saldo']  ?? -1] ?? '0'));

            if ($debit == 0 && $kredit == 0) continue;

            $rows[] = compact('tanggal', 'keterangan', 'debit', 'kredit', 'saldo');
        }

        return $rows;
    }

    // ── Shared helpers ───────────────────────────────────────────────

    private function parseTanggal(string $raw): ?string
    {
        $raw = trim($raw);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd M Y', 'd-M-Y', 'd F Y'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $raw)->format('Y-m-d');
            } catch (\Exception) {}
        }
        if (is_numeric($raw)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw)
                    ->format('Y-m-d');
            } catch (\Exception) {}
        }
        return null;
    }

    private function parseAngka(string $raw): float
    {
        $clean = preg_replace('/[^\d,\.]/', '', trim($raw));
        if (preg_match('/,\d{2}$/', $clean)) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        }
        return (float) $clean;
    }
}
