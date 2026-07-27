<?php

namespace App\Support\Helpers;

use App\Models\Investor;

/**
 * Data tb_investor ternyata hampir 1:1 dengan tb_resto (dibuat per-outlet
 * saat import), bukan 1 investor yang di-share ke banyak resto seperti
 * relasi Investor::restos() menyiratkan — jadi "investor yang sama" tidak
 * bisa diandalkan lewat kesamaan investor_id. KTP dipakai sebagai sinyal
 * paling kuat kalau tersedia di kedua sisi (data nyata menunjukkan KTP
 * identik walau format dash berbeda-beda); kalau salah satu sisi tidak
 * punya KTP, fallback ke nama ternormalisasi (mengabaikan tanda baca/spasi).
 */
class InvestorIdentityMatcher
{
    public static function matches(Investor $a, Investor $b): bool
    {
        $ktpA = self::normalizeKtp($a->ktp);
        $ktpB = self::normalizeKtp($b->ktp);

        if ($ktpA !== '' && $ktpB !== '') {
            return $ktpA === $ktpB;
        }

        return self::normalizeName($a->nama_investor) === self::normalizeName($b->nama_investor);
    }

    private static function normalizeKtp(?string $ktp): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $ktp));
    }

    private static function normalizeName(?string $name): string
    {
        return strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', ' ', (string) $name)));
    }
}
