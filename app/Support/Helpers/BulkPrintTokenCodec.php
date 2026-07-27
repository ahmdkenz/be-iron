<?php

namespace App\Support\Helpers;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Encode/decode payload (mis. daftar invoice_id + konteks investor) ke dalam
 * satu string url-safe untuk dititipkan sebagai route segment pada signed URL
 * publik. Tidak ada tabel/kolom DB yang menyimpan payload ini — snapshot-nya
 * murni hidup di dalam token terenkripsi, sengaja meniru keputusan revert
 * fitur "bundle_share_token" (lihat memory project_bulk_share_delete /
 * histori commit e7c65584) yang menghindari kolom fisik untuk kasus serupa.
 */
class BulkPrintTokenCodec
{
    public static function encode(array $payload): string
    {
        $encrypted = Crypt::encryptString(json_encode($payload));

        return rtrim(strtr($encrypted, '+/', '-_'), '=');
    }

    /**
     * @throws RuntimeException kalau token tidak valid/rusak/tidak bisa didekripsi.
     */
    public static function decode(string $token): array
    {
        $padded = strtr($token, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        try {
            $decrypted = Crypt::decryptString($padded);
        } catch (\Throwable $e) {
            throw new RuntimeException('Token tidak valid.', previous: $e);
        }

        $payload = json_decode($decrypted, true);

        if (!is_array($payload)) {
            throw new RuntimeException('Payload token tidak valid.');
        }

        return $payload;
    }
}
