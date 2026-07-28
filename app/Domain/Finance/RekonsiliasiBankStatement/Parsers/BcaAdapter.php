<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Parsers;

/**
 * Adapter untuk e-Statement BCA (format CSV).
 * Format kolom BCA: Tanggal, Keterangan, Cabang, Jumlah, Saldo
 * Kolom "Jumlah" diikuti " CR" (kredit/masuk) atau " DB" (debit/keluar).
 * Menggunakan detectHeaderRow() dari AbstractBankParser untuk deteksi header yang robust.
 */
class BcaAdapter extends AbstractBankParser
{
    public function parseInChunks(string $filePath, callable $onChunk, int $chunkSize = 750): void
    {
        $data = $this->readCsvRows($filePath);

        $detected = $this->detectHeaderRow($data, minScore: 2); // BCA CSV hanya punya ~4 kolom
        if ($detected['rowIdx'] === -1) {
            throw new \RuntimeException('Format file BCA tidak dikenali: baris header tidak ditemukan.');
        }

        $headerCols  = $data[$detected['rowIdx']];
        $jumlahIdx   = $this->findColIndex($headerCols, ['jumlah']);
        $saldoIdx    = $this->findColIndex($headerCols, ['saldo']);
        $tanggalIdx  = $detected['colMap']['tanggal']      ?? 0;
        $waktuIdx    = $detected['colMap']['waktu']        ?? -1;
        $ketIdx      = $detected['colMap']['keterangan']   ?? 1;
        $noRefIdx    = $detected['colMap']['no_referensi'] ?? -1;

        $totalRows = count($data);
        $buffer    = [];
        $errors    = [];
        $scanned   = 0;

        for ($i = $detected['rowIdx'] + 1; $i < $totalRows; $i++) {
            $rowNumber = $i + 1;
            $cols      = $data[$i];
            $scanned++;

            $tanggalRaw = trim($cols[$tanggalIdx] ?? '');
            $ketRaw     = trim($cols[$ketIdx] ?? '');
            $jumlahRaw  = trim($cols[$jumlahIdx] ?? '');
            $saldoRaw   = trim($cols[$saldoIdx]  ?? '');

            if ($tanggalRaw === '' && $ketRaw === '' && $jumlahRaw === '' && $saldoRaw === '') {
                // baris kosong, lewati diam-diam
            } elseif (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $tanggalRaw)) {
                $errors[] = ['row' => $rowNumber, 'message' => "Tanggal tidak valid: \"{$tanggalRaw}\" (format harus DD/MM/YYYY)."];
            } else {
                $tanggal = $this->parseTanggal($tanggalRaw);
                $upper   = strtoupper($jumlahRaw);
                $debit   = 0.0;
                $kredit  = 0.0;

                if (str_ends_with($upper, 'CR')) {
                    $kredit = $this->parseAngka(rtrim(substr($jumlahRaw, 0, -2)));
                } elseif (str_ends_with($upper, 'DB')) {
                    $debit = $this->parseAngka(rtrim(substr($jumlahRaw, 0, -2)));
                } elseif ($jumlahRaw !== '') {
                    $errors[] = ['row' => $rowNumber, 'message' => "Format Jumlah tidak dikenali: \"{$jumlahRaw}\" (harus diakhiri CR atau DB)."];
                    $tanggal = null; // tandai supaya tidak ikut masuk sebagai baris valid di bawah
                }

                if ($tanggal !== null && $ketRaw !== '' && ($debit > 0 || $kredit > 0)) {
                    $buffer[] = [
                        'tanggal'         => $tanggal,
                        'waktu_transaksi' => $waktuIdx >= 0 ? $this->parseWaktu(trim($cols[$waktuIdx] ?? '')) : null,
                        'keterangan'      => $ketRaw,
                        'no_referensi'    => $noRefIdx >= 0 ? (trim($cols[$noRefIdx] ?? '') ?: null) : null,
                        'debit'           => $debit,
                        'kredit'          => $kredit,
                        'saldo'           => $this->parseAngka($saldoRaw),
                    ];
                }
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

    private function readCsvRows(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // hapus BOM
        if (!mb_detect_encoding($content, 'UTF-8', true)) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $content));

        $data = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $data[] = str_getcsv($line, ',', '"');
        }

        return $data;
    }

    private function findColIndex(array $headerCols, array $keywords): int
    {
        foreach ($headerCols as $idx => $cell) {
            $cell = strtolower(trim($cell));
            foreach ($keywords as $kw) {
                if (str_contains($cell, $kw)) return $idx;
            }
        }
        return -1;
    }
}
