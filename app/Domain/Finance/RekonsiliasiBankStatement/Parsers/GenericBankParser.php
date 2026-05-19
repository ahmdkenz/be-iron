<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Parsers;

/**
 * Universal parser untuk bank dengan format XLSX standar (Mandiri, BNI, BRI, CIMB).
 * Menerima column mapping config dari BankColumnMapping, lalu:
 *   1. Auto-detect baris header via scoring (AbstractBankParser::detectHeaderRow)
 *   2. Resolve colMap: prioritas config bank, fallback auto-detect
 *   3. Ekstrak baris data → canonical format
 */
class GenericBankParser extends AbstractBankParser
{
    public function __construct(private array $columnMapping) {}

    public function parse(string $filePath): array
    {
        $data = $this->loadXlsx($filePath);

        $detected = $this->detectHeaderRow($data);
        if ($detected['rowIdx'] === -1) {
            throw new \RuntimeException('Format file tidak dikenali: baris header tidak ditemukan. Pastikan file sesuai dengan bank yang dipilih.');
        }

        $headerRow = $data[$detected['rowIdx']];
        $colMap    = $this->resolveColMap($headerRow, $detected['colMap']);

        $rows = [];
        for ($i = $detected['rowIdx'] + 1; $i < count($data); $i++) {
            $row = $this->extractRow($data[$i], $colMap);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
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

    private function extractRow(array $row, array $colMap): ?array
    {
        $tanggalRaw = trim((string) ($row[$colMap['tanggal'] ?? -1] ?? ''));
        if ($tanggalRaw === '') return null;

        $tanggal = $this->parseTanggal($tanggalRaw);
        if ($tanggal === null) return null;

        $debit  = $this->parseAngka((string) ($row[$colMap['debit']  ?? -1] ?? '0'));
        $kredit = $this->parseAngka((string) ($row[$colMap['kredit'] ?? -1] ?? '0'));

        if ($debit == 0 && $kredit == 0) return null;

        return [
            'tanggal'    => $tanggal,
            'keterangan' => trim((string) ($row[$colMap['keterangan'] ?? -1] ?? '')),
            'debit'      => $debit,
            'kredit'     => $kredit,
            'saldo'      => $this->parseAngka((string) ($row[$colMap['saldo'] ?? -1] ?? '0')),
        ];
    }
}
