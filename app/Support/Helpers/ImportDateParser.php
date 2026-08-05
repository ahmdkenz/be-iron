<?php

namespace App\Support\Helpers;

use PhpOffice\PhpSpreadsheet\Shared\Date as PhpSpreadsheetDate;
use Throwable;

/**
 * Parser tanggal untuk kolom import Excel/CSV (Opening Balance, Invoice, dst).
 *
 * Mengembalikan null (bukan string mentah) untuk input yang benar-benar tidak
 * bisa diparse, supaya pemanggil bisa memperlakukannya sebagai error validasi
 * per-baris alih-alih meneruskan string sampah ke Carbon::parse() di lapisan
 * lain (yang tidak mengenal nama bulan Indonesia dan akan throw).
 */
class ImportDateParser
{
    private const BULAN = [
        'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4,
        'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8,
        'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
    ];

    public static function parse(mixed $val): ?string
    {
        $s = trim((string) $val);
        if ($s === '' || $s === '-') {
            return null;
        }

        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/', $s, $m)) {
            $bulan = self::BULAN[strtolower($m[2])] ?? null;
            if ($bulan !== null) {
                return sprintf('%04d-%02d-%02d', (int) $m[3], $bulan, (int) $m[1]);
            }

            return null;
        }
        if (is_numeric($s)) {
            try {
                return PhpSpreadsheetDate::excelToDateTimeObject((float) $s)->format('Y-m-d');
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
