<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Parsers;

/**
 * Adapter untuk rekening koran BSI (Bank Syariah Indonesia).
 * Mendukung dua format export: CSV (BSI Mobile/BizNet) dan XLSX.
 * Menggunakan detectHeaderRow() dari AbstractBankParser untuk deteksi header yang robust.
 */
class BsiAdapter extends AbstractBankParser
{
    public function parseInChunks(string $filePath, callable $onChunk, int $chunkSize = 750): void
    {
        if ($this->isCsv($filePath)) {
            $this->parseCsvInChunks($filePath, $onChunk, $chunkSize);
        } else {
            $this->parseXlsxInChunks($filePath, $onChunk, $chunkSize);
        }
    }

    private function isCsv(string $filePath): bool
    {
        return strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'csv';
    }

    // ── CSV path ──────────────────────────────────────────────────────

    private function parseCsvInChunks(string $filePath, callable $onChunk, int $chunkSize): void
    {
        $content = file_get_contents($filePath);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (!mb_detect_encoding($content, 'UTF-8', true)) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $lines     = explode("\n", str_replace("\r\n", "\n", $content));
        $delimiter = $this->detectDelimiter($lines);

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

        $colMap    = $detected['colMap'];
        $totalRows = count($data);
        $buffer    = [];
        $errors    = [];
        $scanned   = 0;

        for ($i = $detected['rowIdx'] + 1; $i < $totalRows; $i++) {
            $rowNumber = $i + 1;
            $result    = $this->extractOrValidateRow($rowNumber, $data[$i], $colMap);
            $scanned++;

            if ($result['error'] !== null) {
                $errors[] = $result['error'];
            } elseif ($result['row'] !== null) {
                $buffer[] = $result['row'];
            }

            if ($scanned >= $chunkSize) {
                $onChunk($buffer, $errors, $scanned);
                $buffer  = [];
                $errors  = [];
                $scanned = 0;
            }
        }

        if ($scanned > 0) {
            $onChunk($buffer, $errors, $scanned);
        }
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

    private function parseXlsxInChunks(string $filePath, callable $onChunk, int $chunkSize): void
    {
        $this->chunkedXlsxRows(
            $filePath,
            $chunkSize,
            function (array $preview) {
                $detected = $this->detectHeaderRow($preview, minScore: 3);
                if ($detected['rowIdx'] === -1) {
                    throw new \RuntimeException('Format file BSI tidak dikenali: baris header tidak ditemukan.');
                }

                return [
                    'headerRowIdx' => $detected['rowIdx'],
                    'colMap'       => $detected['colMap'],
                ];
            },
            function (array $rangeRows, int $startRow, array $colMap) use ($onChunk) {
                $validRows = [];
                $errors    = [];

                foreach ($rangeRows as $offset => $row) {
                    $result = $this->extractOrValidateRow($startRow + $offset, $row, $colMap);
                    if ($result['error'] !== null) {
                        $errors[] = $result['error'];
                    } elseif ($result['row'] !== null) {
                        $validRows[] = $result['row'];
                    }
                }

                $onChunk($validRows, $errors, count($rangeRows));
            }
        );
    }
}
