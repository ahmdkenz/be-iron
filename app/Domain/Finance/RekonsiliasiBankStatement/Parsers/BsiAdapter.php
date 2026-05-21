<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Parsers;

/**
 * Adapter untuk rekening koran BSI (Bank Syariah Indonesia).
 * Mendukung dua format export: CSV (BSI Mobile/BizNet) dan XLSX.
 * Menggunakan detectHeaderRow() dari AbstractBankParser untuk deteksi header yang robust.
 */
class BsiAdapter extends AbstractBankParser
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

    // ── CSV path ──────────────────────────────────────────────────────

    private function parseCsv(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (!mb_detect_encoding($content, 'UTF-8', true)) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $lines     = explode("\n", str_replace("\r\n", "\n", $content));
        $delimiter = $this->detectDelimiter($lines);

        // Bangun 2D array untuk detectHeaderRow()
        $data = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $data[] = str_getcsv($line, $delimiter, '"');
        }

        $detected = $this->detectHeaderRow($data, minScore: 3);
        if ($detected['rowIdx'] === -1) {
            throw new \RuntimeException('Format CSV BSI tidak dikenali: baris header tidak ditemukan.');
        }

        $colMap = $detected['colMap'];
        $rows   = [];

        for ($i = $detected['rowIdx'] + 1; $i < count($data); $i++) {
            $cols = $data[$i];

            $tanggalRaw = trim($cols[$colMap['tanggal'] ?? -1] ?? '');
            if ($tanggalRaw === '') continue;

            $tanggal = $this->parseTanggal($tanggalRaw);
            if ($tanggal === null) continue;

            $debit  = $this->parseAngka($cols[$colMap['debit']  ?? -1] ?? '0');
            $kredit = $this->parseAngka($cols[$colMap['kredit'] ?? -1] ?? '0');
            if ($debit == 0 && $kredit == 0) continue;

            $rows[] = [
                'tanggal'      => $tanggal,
                'keterangan'   => trim($cols[$colMap['keterangan'] ?? -1] ?? ''),
                'no_referensi' => isset($colMap['no_referensi']) ? (trim($cols[$colMap['no_referensi']] ?? '') ?: null) : null,
                'debit'        => $debit,
                'kredit'       => $kredit,
                'saldo'        => $this->parseAngka($cols[$colMap['saldo'] ?? -1] ?? '0'),
            ];
        }

        return $rows;
    }

    private function detectDelimiter(array $lines): string
    {
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
        }
        return ',';
    }

    // ── XLSX path ─────────────────────────────────────────────────────

    private function parseXlsx(string $filePath): array
    {
        $data = $this->loadXlsx($filePath);

        $detected = $this->detectHeaderRow($data, minScore: 3);
        if ($detected['rowIdx'] === -1) {
            throw new \RuntimeException('Format file BSI tidak dikenali: baris header tidak ditemukan.');
        }

        $colMap = $detected['colMap'];
        $rows   = [];

        for ($i = $detected['rowIdx'] + 1; $i < count($data); $i++) {
            $row = $data[$i];

            $tanggalRaw = trim((string) ($row[$colMap['tanggal'] ?? -1] ?? ''));
            if ($tanggalRaw === '') continue;

            $tanggal = $this->parseTanggal($tanggalRaw);
            if ($tanggal === null) continue;

            $debit  = $this->parseAngka((string) ($row[$colMap['debit']  ?? -1] ?? '0'));
            $kredit = $this->parseAngka((string) ($row[$colMap['kredit'] ?? -1] ?? '0'));
            if ($debit == 0 && $kredit == 0) continue;

            $rows[] = [
                'tanggal'      => $tanggal,
                'keterangan'   => trim((string) ($row[$colMap['keterangan'] ?? -1] ?? '')),
                'no_referensi' => isset($colMap['no_referensi']) ? (trim((string) ($row[$colMap['no_referensi']] ?? '')) ?: null) : null,
                'debit'        => $debit,
                'kredit'       => $kredit,
                'saldo'        => $this->parseAngka((string) ($row[$colMap['saldo'] ?? -1] ?? '0')),
            ];
        }

        return $rows;
    }
}
