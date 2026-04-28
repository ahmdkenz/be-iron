<?php

namespace App\Support\Helpers;

class Terbilang
{
    private static array $satuan = [
        '', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan',
        'Sepuluh', 'Sebelas', 'Dua Belas', 'Tiga Belas', 'Empat Belas', 'Lima Belas',
        'Enam Belas', 'Tujuh Belas', 'Delapan Belas', 'Sembilan Belas',
    ];

    public static function convert(int $angka): string
    {
        if ($angka === 0) return 'Nol';
        if ($angka < 0)  return 'Minus ' . self::convert(abs($angka));

        return self::proses($angka);
    }

    private static function proses(int $n): string
    {
        if ($n === 0) return '';

        if ($n < 20) return self::$satuan[$n];

        if ($n < 100) {
            $puluh = intdiv($n, 10);
            $sisa  = $n % 10;
            $puluhStr = ['', '', 'Dua Puluh', 'Tiga Puluh', 'Empat Puluh', 'Lima Puluh',
                         'Enam Puluh', 'Tujuh Puluh', 'Delapan Puluh', 'Sembilan Puluh'];
            return trim($puluhStr[$puluh] . ($sisa ? ' ' . self::$satuan[$sisa] : ''));
        }

        if ($n < 1_000) {
            $ratus = intdiv($n, 100);
            $sisa  = $n % 100;
            $r     = $ratus === 1 ? 'Seratus' : self::$satuan[$ratus] . ' Ratus';
            return trim($r . ($sisa ? ' ' . self::proses($sisa) : ''));
        }

        if ($n < 1_000_000) {
            $ribu = intdiv($n, 1_000);
            $sisa = $n % 1_000;
            $r    = $ribu === 1 ? 'Seribu' : self::proses($ribu) . ' Ribu';
            return trim($r . ($sisa ? ' ' . self::proses($sisa) : ''));
        }

        if ($n < 1_000_000_000) {
            $juta = intdiv($n, 1_000_000);
            $sisa = $n % 1_000_000;
            return trim(self::proses($juta) . ' Juta' . ($sisa ? ' ' . self::proses($sisa) : ''));
        }

        $miliar = intdiv($n, 1_000_000_000);
        $sisa   = $n % 1_000_000_000;
        return trim(self::proses($miliar) . ' Miliar' . ($sisa ? ' ' . self::proses($sisa) : ''));
    }
}
