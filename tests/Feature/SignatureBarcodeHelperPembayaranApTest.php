<?php

namespace Tests\Feature;

use App\Models\PembayaranAp;
use App\Support\Helpers\SignatureBarcodeHelper;
use Tests\TestCase;

/**
 * Model PembayaranAp dibuat in-memory (forceFill, tanpa save) karena RefreshDatabase
 * tidak bisa dipakai di project ini (lihat tests/Feature/MasterImportServiceTest.php).
 */
class SignatureBarcodeHelperPembayaranApTest extends TestCase
{
    private function makePembayaran(): PembayaranAp
    {
        $pembayaran = new PembayaranAp();
        $pembayaran->forceFill([
            'id'                  => 1,
            'tanggal_pembayaran'  => '2026-07-20',
            'jumlah_pembayaran'   => 15750000,
        ]);

        return $pembayaran;
    }

    public function test_payload_berisi_semua_detail_voucher(): void
    {
        $pembayaran = $this->makePembayaran();

        $payload = SignatureBarcodeHelper::buildPembayaranApPreparedPayload(
            $pembayaran,
            'Ahmad Nur Hafidz',
            'VP-000123',
            3,
            2,
        );

        $this->assertStringContainsString('Dibuat Oleh: Ahmad Nur Hafidz', $payload);
        $this->assertStringContainsString('No Payment Voucher: VP-000123', $payload);
        $this->assertStringContainsString('Tanggal: 20-07-2026', $payload);
        $this->assertStringContainsString('Total Pembayaran: Rp 15.750.000', $payload);
        $this->assertStringContainsString('Jumlah Tagihan: 3', $payload);
        $this->assertStringContainsString('Jumlah Vendor: 2', $payload);
        $this->assertStringContainsString('Status: Dibuat', $payload);
    }

    public function test_generate_data_uri_menghasilkan_svg_qr(): void
    {
        $pembayaran = $this->makePembayaran();

        $payload = SignatureBarcodeHelper::buildPembayaranApPreparedPayload(
            $pembayaran,
            'Ahmad Nur Hafidz',
            'VP-000123',
            3,
            2,
        );

        $dataUri = SignatureBarcodeHelper::generateDataUri($payload, 250);

        $this->assertNotNull($dataUri);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $dataUri);
    }
}
