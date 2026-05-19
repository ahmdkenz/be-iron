<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Parsers;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parser untuk rekening koran CIMB NIAGA (format XLSX).
 * Kolom standar: Tanggal Transaksi | Keterangan / Deskripsi | Mutasi Debet | Mutasi Kredit | Saldo
 * Header dicari dengan kata kunci "tanggal transaksi", "tgl transaksi", atau fallback "tanggal".
 */
class CimbParser implements BankParserInterface
{
    public function parse(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet       = $spreadsheet->getActiveSheet();
        $data        = $sheet->toArray(null, true, true, false);

        $headerRow = -1;
        foreach ($data as $rowIdx => $row) {
            foreach ($row as $cell) {
                $cellStr = strtolower(trim((string) $cell));
                if (str_contains($cellStr, 'tanggal') && str_contains($cellStr, 'transaksi')) {
                    $headerRow = $rowIdx;
                    break 2;
                }
                if (str_contains($cellStr, 'tgl') && str_contains($cellStr, 'transaksi')) {
                    $headerRow = $rowIdx;
                    break 2;
                }
            }
        }

        // Fallback: cari kolom "tanggal" saja
        if ($headerRow === -1) {
            foreach ($data as $rowIdx => $row) {
                foreach ($row as $cell) {
                    $cellStr = strtolower(trim((string) $cell));
                    if ($cellStr === 'tanggal' || $cellStr === 'tgl') {
                        $headerRow = $rowIdx;
                        break 2;
                    }
                }
            }
        }

        if ($headerRow === -1) {
            throw new \RuntimeException('Format file CIMB NIAGA tidak dikenali: baris header tidak ditemukan.');
        }

        $headers = $data[$headerRow];
        $colMap  = [];
        foreach ($headers as $colIdx => $header) {
            $h = strtolower(trim((string) $header));
            if (str_contains($h, 'tanggal') || str_contains($h, 'tgl')) $colMap['tanggal'] = $colMap['tanggal'] ?? $colIdx;
            if (str_contains($h, 'keterangan') || str_contains($h, 'deskripsi') || str_contains($h, 'transaksi')) $colMap['keterangan'] = $colMap['keterangan'] ?? $colIdx;
            if ((str_contains($h, 'debet') || str_contains($h, 'debit')) && !str_contains($h, 'kredit')) $colMap['debit'] = $colMap['debit'] ?? $colIdx;
            if (str_contains($h, 'kredit') && !str_contains($h, 'debet') && !str_contains($h, 'debit')) $colMap['kredit'] = $colMap['kredit'] ?? $colIdx;
            if ($h === 'saldo' || str_contains($h, 'saldo akhir')) $colMap['saldo'] = $colMap['saldo'] ?? $colIdx;
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
        // Format "1.234.567,89"
        if (preg_match('/,\d{2}$/', $clean)) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        }
        return (float) $clean;
    }
}
