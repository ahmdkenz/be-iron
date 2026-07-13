<?php

namespace Tests\Feature;

use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\PendapatanDiMuka\Services\PendapatanDiMukaService;
use App\Domain\Finance\PembayaranAr\Services\PembayaranArService;
use App\Models\Invoice;
use App\Models\KlienAr;
use App\Models\PembayaranAr;
use App\Models\Resto;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Unit test untuk buildBuktiPath()/sanitizePathSegment() di PembayaranArService.
 * Menggunakan reflection + model in-memory (bukan DB) karena RefreshDatabase
 * tidak bisa dipakai di project ini (lihat tests/Feature/MasterImportServiceTest.php).
 */
class PembayaranArServiceBuktiPathTest extends TestCase
{
    private PembayaranArService $service;
    private \ReflectionClass $ref;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PembayaranArService(
            $this->createMock(InvoiceService::class),
            $this->createMock(PendapatanDiMukaService::class),
        );

        $this->ref = new \ReflectionClass($this->service);
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $m = $this->ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->service, ...$args);
    }

    private function makePembayaran(int $id, int $invoiceId): PembayaranAr
    {
        $pembayaran = new PembayaranAr();
        $pembayaran->forceFill(['id' => $id, 'invoice_id' => $invoiceId]);

        return $pembayaran;
    }

    private function fakeUploadedFile(string $name = 'bukti.png'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 10, 'image/png');
    }

    public function test_path_lengkap_klien_resto_invoice(): void
    {
        $klien = new KlienAr();
        $klien->forceFill(['id' => 1, 'kode_klien' => 'CL001', 'nama_klien' => 'PT ABC']);

        $resto = new Resto();
        $resto->forceFill(['id' => 1, 'kode_resto' => 'R001', 'nama_resto' => 'Resto Senayan']);

        $invoice = new Invoice();
        $invoice->forceFill([
            'id'              => 10,
            'klien_ar_id'     => 1,
            'no_invoice'      => 'INV-2026-07-001',
            'tanggal_invoice' => '2026-07-05',
        ]);
        $invoice->setRelation('klienAr', $klien);
        $invoice->setRelation('resto', $resto);
        $invoice->setRelation('items', collect());

        $pembayaran = $this->makePembayaran(123, 10);
        $file       = $this->fakeUploadedFile();

        $path = $this->invoke('buildBuktiPath', $pembayaran, $file, $invoice);

        $this->assertStringStartsWith(
            'bukti-bayar/CL001 - PT ABC/R001 - Resto Senayan/2026/07/INV-2026-07-001/pembayaran-123-',
            $path
        );
        $this->assertStringEndsWith('.png', $path);
    }

    public function test_nomor_invoice_dengan_slash_disanitasi(): void
    {
        $klien = new KlienAr();
        $klien->forceFill(['id' => 1, 'kode_klien' => 'CL001', 'nama_klien' => 'PT ABC']);

        $invoice = new Invoice();
        $invoice->forceFill([
            'id'              => 11,
            'klien_ar_id'     => 1,
            'no_invoice'      => 'INV/2026/07/002',
            'tanggal_invoice' => '2026-07-05',
        ]);
        $invoice->setRelation('klienAr', $klien);
        $invoice->setRelation('resto', null);
        $invoice->setRelation('items', collect());

        $pembayaran = $this->makePembayaran(124, 11);
        $file       = $this->fakeUploadedFile();

        $path = $this->invoke('buildBuktiPath', $pembayaran, $file, $invoice);

        $this->assertStringContainsString('/INV-2026-07-002/', $path);
        $this->assertStringNotContainsString('INV/2026/07/002', $path);
    }

    public function test_fallback_tanpa_resto_via_klien_ar_resto(): void
    {
        $resto = new Resto();
        $resto->forceFill(['id' => 2, 'kode_resto' => 'R002', 'nama_resto' => 'Resto Kemang']);

        $klien = new KlienAr();
        $klien->forceFill(['id' => 2, 'kode_klien' => 'CL002', 'nama_klien' => 'PT XYZ']);
        $klien->setRelation('resto', $resto);

        $invoice = new Invoice();
        $invoice->forceFill([
            'id'              => 12,
            'klien_ar_id'     => 2,
            'resto_id'        => null,
            'no_invoice'      => 'INV-2026-07-003',
            'tanggal_invoice' => '2026-07-05',
        ]);
        $invoice->setRelation('klienAr', $klien);
        $invoice->setRelation('resto', null);
        $invoice->setRelation('items', collect());

        $pembayaran = $this->makePembayaran(125, 12);
        $file       = $this->fakeUploadedFile();

        $path = $this->invoke('buildBuktiPath', $pembayaran, $file, $invoice);

        $this->assertStringContainsString('R002 - Resto Kemang', $path);
    }

    public function test_fallback_tanpa_resto_sama_sekali(): void
    {
        $klien = new KlienAr();
        $klien->forceFill(['id' => 3, 'kode_klien' => 'CL003', 'nama_klien' => 'PT DEF']);

        $invoice = new Invoice();
        $invoice->forceFill([
            'id'              => 13,
            'klien_ar_id'     => 3,
            'no_invoice'      => 'INV-2026-07-004',
            'tanggal_invoice' => '2026-07-05',
        ]);
        $invoice->setRelation('klienAr', $klien);
        $invoice->setRelation('resto', null);
        $invoice->setRelation('items', collect());

        $pembayaran = $this->makePembayaran(126, 13);
        $file       = $this->fakeUploadedFile();

        $path = $this->invoke('buildBuktiPath', $pembayaran, $file, $invoice);

        $this->assertStringContainsString('/Tanpa Resto/', $path);
    }

    public function test_sanitize_path_segment_karakter_ilegal(): void
    {
        $result = $this->invoke('sanitizePathSegment', 'A/B\\C:D*E?F"G<H>I|J', 'fallback');

        $this->assertSame('A-B-C-D-E-F-G-H-I-J', $result);
    }

    public function test_sanitize_path_segment_kosong_pakai_fallback(): void
    {
        $result = $this->invoke('sanitizePathSegment', '   ', 'Tanpa Resto');

        $this->assertSame('Tanpa Resto', $result);
    }

    public function test_sanitize_path_segment_trim_dash_dan_titik(): void
    {
        $result = $this->invoke('sanitizePathSegment', ' - .Nama Klien. - ', 'fallback');

        $this->assertSame('Nama Klien', $result);
    }
}
