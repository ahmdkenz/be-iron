<?php

namespace Tests\Unit;

use App\Support\Helpers\BulkPrintTokenCodec;
use RuntimeException;
use Tests\TestCase;

/**
 * Extends Tests\TestCase (bukan PHPUnit\Framework\TestCase murni) karena
 * Crypt::encryptString butuh APP_KEY dari container aplikasi yang sudah
 * di-boot — tidak menyentuh DB sama sekali, jadi tetap aman dari masalah
 * migrate:fresh (lihat project_test_db_migrate_fresh_broken di memory).
 */
class BulkPrintTokenCodecTest extends TestCase
{
    public function test_encode_decode_round_trip_mengembalikan_payload_sama(): void
    {
        $payload = [
            'invoice_ids'       => [1, 2, 3],
            'investor_id'       => 10,
            'investor_nama'     => 'PT Investor Contoh',
            'klien_anchor_nama' => 'Resto A',
            'pic_ar_nama'       => 'Budi',
            'tanggal_dari'      => '2026-07-01',
            'tanggal_sampai'    => '2026-07-31',
        ];

        $token = BulkPrintTokenCodec::encode($payload);

        $this->assertIsString($token);
        $this->assertStringNotContainsString('/', $token);
        $this->assertStringNotContainsString('+', $token);
        $this->assertStringNotContainsString('=', $token);

        $decoded = BulkPrintTokenCodec::decode($token);

        $this->assertSame($payload, $decoded);
    }

    public function test_decode_token_acak_melempar_exception(): void
    {
        $this->expectException(RuntimeException::class);

        BulkPrintTokenCodec::decode('token-acak-tidak-valid-sama-sekali');
    }

    public function test_decode_token_yang_dirusak_melempar_exception(): void
    {
        $token = BulkPrintTokenCodec::encode(['invoice_ids' => [1]]);
        $tampered = substr($token, 0, -3) . 'xyz';

        $this->expectException(RuntimeException::class);

        BulkPrintTokenCodec::decode($tampered);
    }
}
