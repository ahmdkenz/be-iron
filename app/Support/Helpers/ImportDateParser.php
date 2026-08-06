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

    /** Singkatan bulan Indonesia + Inggris (keduanya beredar di data nyata). */
    private const BULAN_SINGKATAN = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
        'mei' => 5, 'may' => 5, 'jun' => 6, 'jul' => 7,
        'agu' => 8, 'ags' => 8, 'aug' => 8, 'sep' => 9,
        'okt' => 10, 'oct' => 10, 'nov' => 11,
        'des' => 12, 'dec' => 12,
    ];

    public static function parse(mixed $val): ?string
    {
        $s = trim((string) $val);
        if ($s === '' || $s === '-') {
            return null;
        }

        // Numerik, tanggal duluan: 28-05-2026 / 28/05/2026 / 28.05.2026 / 28-05-26
        // (pemisah - / . dan tahun 2 atau 4 digit — semua varian yang bisa muncul
        // saat file diedit/disimpan ulang lewat Excel dengan locale berbeda).
        if (preg_match('/^(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{2}|\d{4})$/', $s, $m)) {
            $tahun = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];

            return sprintf('%04d-%02d-%02d', $tahun, (int) $m[2], (int) $m[1]);
        }
        // Numerik, tahun duluan (ISO-like): 2026-05-28 / 2026/05/28 / 2026.05.28
        if (preg_match('/^(\d{4})[.\-\/](\d{1,2})[.\-\/](\d{1,2})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        // Nama bulan (lengkap Indonesia atau singkatan Indonesia/Inggris), pemisah
        // spasi atau dash, tahun 2 atau 4 digit: "1 Juli 2026", "01-Jul-26", "1-Des-2026".
        if (preg_match('/^(\d{1,2})[\s\-]+([A-Za-z]+)[\s\-]+(\d{2}|\d{4})$/', $s, $m)) {
            $bulan = self::BULAN[strtolower($m[2])] ?? self::BULAN_SINGKATAN[strtolower($m[2])] ?? null;
            if ($bulan === null) {
                return null;
            }
            $tahun = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];

            return sprintf('%04d-%02d-%02d', $tahun, $bulan, (int) $m[1]);
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
