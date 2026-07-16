<?php

namespace App\Domain\Finance\ApShz360Sync\Support;

class VendorNameMatcher
{
    /**
     * Trim + uppercase saja (TIDAK collapse spasi ganda di tengah) supaya hasilnya selalu
     * identik dengan pencocokan SQL `UPPER(TRIM(kolom))` yang dipakai di sisi query — kalau
     * dua normalisasi ini berbeda, cascade/auto-resolve vendor bisa diam-diam tidak jalan.
     */
    public static function normalize(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $normalized = trim($name);

        return $normalized === '' ? null : mb_strtoupper($normalized);
    }
}
