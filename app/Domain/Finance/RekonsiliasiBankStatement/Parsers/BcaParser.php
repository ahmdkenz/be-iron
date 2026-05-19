<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Parsers;

use Carbon\Carbon;

/**
 * Parser untuk e-Statement BCA (format CSV).
 * Format kolom: Tanggal, Keterangan, Cabang, Jumlah, Saldo
 * Kolom Jumlah diikuti "CR" (kredit/masuk) atau "DB" (debit/keluar).
 * Data transaksi dimulai setelah baris header yang mengandung kata "Tanggal".
 */
class BcaParser implements BankParserInterface
{
    public function parse(string $filePath): array
    {
        // Baca raw content, handle encoding Windows-1252 / UTF-8 BOM
        $content = file_get_contents($filePath);
        // Hapus BOM jika ada
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        // Konversi Windows-1252 ke UTF-8 jika perlu
        if (!mb_detect_encoding($content, 'UTF-8', true)) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $content));

        // Cari baris header yang mengandung "Tanggal"
        $headerLine = -1;
        foreach ($lines as $i => $line) {
            if (stripos($line, 'Tanggal') !== false && stripos($line, 'Keterangan') !== false) {
                $headerLine = $i;
                break;
            }
        }

        if ($headerLine === -1) {
            throw new \RuntimeException('Format file BCA tidak dikenali: baris header tidak ditemukan.');
        }

        $rows = [];

        for ($i = $headerLine + 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;

            // Parse CSV dengan memperhatikan tanda kutip
            $cols = str_getcsv($line, ',', '"');

            if (count($cols) < 4) continue;

            $tanggalRaw  = trim($cols[0] ?? '');
            $keterangan  = trim($cols[1] ?? '');
            $jumlahRaw   = trim($cols[3] ?? '');
            $saldoRaw    = trim($cols[4] ?? '');

            // Validasi tanggal format DD/MM/YYYY
            if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $tanggalRaw)) continue;

            $tanggal = Carbon::createFromFormat('d/m/Y', $tanggalRaw)->format('Y-m-d');
            $saldo   = $this->parseAngka($saldoRaw);

            // BCA: Jumlah diikuti " CR" atau " DB"
            $debit  = 0.0;
            $kredit = 0.0;
            if (str_ends_with(strtoupper($jumlahRaw), 'CR')) {
                $kredit = $this->parseAngka(str_ireplace('CR', '', $jumlahRaw));
            } elseif (str_ends_with(strtoupper($jumlahRaw), 'DB')) {
                $debit = $this->parseAngka(str_ireplace('DB', '', $jumlahRaw));
            } else {
                continue; // bukan baris transaksi valid
            }

            if (empty($keterangan)) continue;

            $rows[] = compact('tanggal', 'keterangan', 'debit', 'kredit', 'saldo');
        }

        return $rows;
    }

    private function parseAngka(string $raw): float
    {
        // Format BCA: "1.234.567,89" → 1234567.89
        $clean = str_replace(['.', ' '], '', trim($raw));
        $clean = str_replace(',', '.', $clean);
        return (float) $clean;
    }
}
