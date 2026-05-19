<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Parsers;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parser untuk rekening koran BNI (format XLSX).
 * Kolom standar: Tanggal | Keterangan | Mutasi Debet | Mutasi Kredit | Saldo
 * Header dicari dengan kata kunci "Tanggal".
 */
class BniParser implements BankParserInterface
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
                if ($cellStr === 'tanggal' || $cellStr === 'tgl') {
                    $headerRow = $rowIdx;
                    break 2;
                }
            }
        }

        if ($headerRow === -1) {
            throw new \RuntimeException('Format file BNI tidak dikenali: baris header tidak ditemukan.');
        }

        $headers = $data[$headerRow];
        $colMap  = [];
        foreach ($headers as $colIdx => $header) {
            $h = strtolower(trim((string) $header));
            if ($h === 'tanggal' || $h === 'tgl') $colMap['tanggal'] = $colIdx;
            elseif (str_contains($h, 'keterangan') || str_contains($h, 'uraian') || str_contains($h, 'transaksi')) $colMap['keterangan'] = $colIdx;
            elseif (str_contains($h, 'debet') || str_contains($h, 'debit')) $colMap['debit'] = $colIdx;
            elseif (str_contains($h, 'kredit')) $colMap['kredit'] = $colIdx;
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
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd M Y', 'd-M-Y'] as $fmt) {
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
