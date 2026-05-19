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
    public function parse(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // hapus BOM
        if (!mb_detect_encoding($content, 'UTF-8', true)) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $content));

        // Ubah semua baris CSV menjadi array 2D untuk detectHeaderRow()
        $data = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $data[] = str_getcsv($line, ',', '"');
        }

        $detected = $this->detectHeaderRow($data, minScore: 2); // BCA CSV hanya punya ~4 kolom
        if ($detected['rowIdx'] === -1) {
            throw new \RuntimeException('Format file BCA tidak dikenali: baris header tidak ditemukan.');
        }

        // Cari index kolom Jumlah dan Saldo dari baris header asli
        $headerCols  = $data[$detected['rowIdx']];
        $jumlahIdx   = $this->findColIndex($headerCols, ['jumlah']);
        $saldoIdx    = $this->findColIndex($headerCols, ['saldo']);
        $tanggalIdx  = $detected['colMap']['tanggal']    ?? 0;
        $ketIdx      = $detected['colMap']['keterangan'] ?? 1;

        $rows = [];
        for ($i = $detected['rowIdx'] + 1; $i < count($data); $i++) {
            $cols = $data[$i];

            $tanggalRaw = trim($cols[$tanggalIdx] ?? '');
            // Validasi format tanggal DD/MM/YYYY
            if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $tanggalRaw)) continue;

            $tanggal    = $this->parseTanggal($tanggalRaw);
            if ($tanggal === null) continue;

            $keterangan = trim($cols[$ketIdx] ?? '');
            if ($keterangan === '') continue;

            $jumlahRaw = trim($cols[$jumlahIdx] ?? '');
            $saldoRaw  = trim($cols[$saldoIdx]  ?? '');

            // BCA: Jumlah diikuti " CR" atau " DB"
            $debit  = 0.0;
            $kredit = 0.0;
            $upper  = strtoupper($jumlahRaw);
            if (str_ends_with($upper, 'CR')) {
                $kredit = $this->parseAngka(rtrim(substr($jumlahRaw, 0, -2)));
            } elseif (str_ends_with($upper, 'DB')) {
                $debit = $this->parseAngka(rtrim(substr($jumlahRaw, 0, -2)));
            } else {
                continue;
            }

            $rows[] = [
                'tanggal'    => $tanggal,
                'keterangan' => $keterangan,
                'debit'      => $debit,
                'kredit'     => $kredit,
                'saldo'      => $this->parseAngka($saldoRaw),
            ];
        }

        return $rows;
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
