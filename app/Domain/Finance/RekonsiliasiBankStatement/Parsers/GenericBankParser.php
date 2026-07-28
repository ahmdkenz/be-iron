<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Parsers;

/**
 * Universal parser untuk bank dengan format XLSX standar (Mandiri, BNI, BRI, CIMB).
 * Menerima column mapping config dari BankColumnMapping, lalu:
 *   1. Auto-detect baris header via scoring (AbstractBankParser::detectHeaderRow)
 *   2. Resolve colMap: prioritas config bank, fallback auto-detect
 *   3. Baca & validasi baris data per chunk (AbstractBankParser::chunkedXlsxRows)
 */
class GenericBankParser extends AbstractBankParser
{
    public function __construct(private array $columnMapping) {}

    public function parseInChunks(string $filePath, callable $onChunk, int $chunkSize = 750): void
    {
        $this->chunkedXlsxRows(
            $filePath,
            $chunkSize,
            function (array $preview) {
                $detected = $this->detectHeaderRow($preview);
                if ($detected['rowIdx'] === -1) {
                    throw new \RuntimeException('Format file tidak dikenali: baris header tidak ditemukan. Pastikan file sesuai dengan bank yang dipilih.');
                }

                return [
                    'headerRowIdx' => $detected['rowIdx'],
                    'colMap'       => $this->resolveColMap($preview[$detected['rowIdx']], $detected['colMap']),
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

    /**
     * Gabungkan config bank ($this->columnMapping) dengan hasil auto-detect.
     * Config bank menang jika ada match nama kolom yang tepat.
     * Auto-detect dipakai sebagai fallback per field.
     */
    private function resolveColMap(array $headerRow, array $autoDetected): array
    {
        $resolved = $autoDetected; // mulai dari hasil scoring

        foreach ($this->columnMapping as $field => $variants) {
            foreach ($headerRow as $colIdx => $cell) {
                $cell = strtolower(trim((string) $cell));
                foreach ($variants as $kw) {
                    if (str_contains($cell, $kw)) {
                        $resolved[$field] = $colIdx;
                        break 2;
                    }
                }
            }
        }

        return $resolved;
    }
}
