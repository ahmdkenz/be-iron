<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Parsers;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parser untuk rekening koran Bank Mandiri (format XLSX).
 * Kolom standar: Tanggal Transaksi | Tanggal Nilai | Deskripsi | Debet | Kredit | Saldo
 * Baris header dinamis — dicari dengan kata kunci "Tanggal Transaksi" atau "TANGGAL".
 */
class MandiriParser implements BankParserInterface
{
    public function parse(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet       = $spreadsheet->getActiveSheet();
        $data        = $sheet->toArray(null, true, true, false);

        // Cari baris header
        $headerRow = -1;
        $colMap    = [];
        foreach ($data as $rowIdx => $row) {
            foreach ($row as $colIdx => $cell) {
                $cellStr = strtolower(trim((string) $cell));
                if (str_contains($cellStr, 'tanggal') && str_contains($cellStr, 'transaksi')) {
                    $headerRow = $rowIdx;
                    break 2;
                }
                // Fallback: cari "TANGGAL" saja
                if ($cellStr === 'tanggal') {
                    $headerRow = $rowIdx;
                    break 2;
                }
            }
        }

        if ($headerRow === -1) {
            throw new \RuntimeException('Format file Mandiri tidak dikenali: baris header tidak ditemukan.');
        }

        // Map kolom berdasarkan header
        $headers = $data[$headerRow];
        foreach ($headers as $colIdx => $header) {
            $h = strtolower(trim((string) $header));
            if (str_contains($h, 'tanggal') && str_contains($h, 'transaksi')) $colMap['tanggal'] = $colIdx;
            elseif ($h === 'tanggal') $colMap['tanggal'] = $colIdx;
            elseif (str_contains($h, 'deskripsi') || str_contains($h, 'keterangan') || str_contains($h, 'uraian')) $colMap['keterangan'] = $colIdx;
            elseif (str_contains($h, 'debet') || $h === 'debit') $colMap['debit'] = $colIdx;
            elseif ($h === 'kredit') $colMap['kredit'] = $colIdx;
            elseif ($h === 'saldo') $colMap['saldo'] = $colIdx;
        }

        $rows = [];
        for ($i = $headerRow + 1; $i < count($data); $i++) {
            $row = $data[$i];

            $tanggalRaw = trim((string) ($row[$colMap['tanggal'] ?? -1] ?? ''));
            if (empty($tanggalRaw)) continue;

            $tanggal = $this->parseTanggal($tanggalRaw);
            if (!$tanggal) continue;

            $keterangan = trim((string) ($row[$colMap['keterangan'] ?? -1] ?? ''));
            $debit      = $this->parseAngka((string) ($row[$colMap['debit'] ?? -1] ?? '0'));
            $kredit     = $this->parseAngka((string) ($row[$colMap['kredit'] ?? -1] ?? '0'));
            $saldo      = $this->parseAngka((string) ($row[$colMap['saldo'] ?? -1] ?? '0'));

            if ($debit == 0 && $kredit == 0) continue;

            $rows[] = compact('tanggal', 'keterangan', 'debit', 'kredit', 'saldo');
        }

        return $rows;
    }

    private function parseTanggal(string $raw): ?string
    {
        $raw = trim($raw);
        // Format DD/MM/YYYY atau DD-MM-YYYY
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $raw)->format('Y-m-d');
            } catch (\Exception) {}
        }
        // PhpSpreadsheet numeric date
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
