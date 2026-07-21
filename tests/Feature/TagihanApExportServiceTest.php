<?php

namespace Tests\Feature;

use App\Domain\Finance\TagihanAp\Services\TagihanApExportService;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\TagihanAp;
use App\Models\TagihanApItem;
use App\Models\VendorAp;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Unit-style test murni in-memory (tanpa DB) — migrate:fresh rusak di project ini
 * (lihat PembayaranArServiceBuktiPathTest.php), jadi relasi Eloquent di-mock via
 * setRelation() bukan query sungguhan.
 */
class TagihanApExportServiceTest extends TestCase
{
    private TagihanApExportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TagihanApExportService();
    }

    public function test_workbook_hanya_punya_1_sheet_bernama_tagihan_ap(): void
    {
        $spreadsheet = $this->service->build(collect());

        $this->assertSame(1, $spreadsheet->getSheetCount());
        $this->assertSame(['Tagihan AP'], $spreadsheet->getSheetNames());
    }

    public function test_tabel_utama_menulis_data_tagihan_di_sisi_kiri(): void
    {
        $tagihan = $this->makeTagihan(1, 'AP-001', 'PT Vendor A', 'VN001', 'ABC', 'PIC A', []);

        $spreadsheet = $this->service->build(collect([$tagihan]));
        $sheet       = $spreadsheet->getSheetByName('Tagihan AP');

        // Rekap table mulai kolom A. Offset 1 (No Tagihan) => B, offset 3 (Vendor) => D,
        // offset 4 (Kode Vendor) => E, offset 5 (Entitas) => F, offset 6 (PIC AP) => G.
        $this->assertSame('AP-001', $sheet->getCell('B4')->getValue());
        $this->assertSame('PT Vendor A', $sheet->getCell('D4')->getValue());
        $this->assertSame('VN001', $sheet->getCell('E4')->getValue());
        $this->assertSame('ABC', $sheet->getCell('F4')->getValue());
        $this->assertSame('PIC A', $sheet->getCell('G4')->getValue());
        $this->assertSame('DITERIMA', $sheet->getCell('R4')->getValue()); // Status
        $this->assertSame('APPROVED', $sheet->getCell('S4')->getValue()); // Approval
    }

    public function test_tabel_detail_menulis_semua_item_dari_setiap_tagihan_di_sisi_kanan(): void
    {
        $items = [
            $this->makeItem('BRG001', 'Barang Satu', 10, 5000),
            $this->makeItem('BRG002', 'Barang Dua', 5, 20000),
        ];
        $tagihan = $this->makeTagihan(2, 'AP-002', 'PT Vendor B', 'VN002', 'XYZ', 'PIC B', $items);

        $spreadsheet = $this->service->build(collect([$tagihan]));
        $sheet       = $spreadsheet->getSheetByName('Tagihan AP');

        // Detail table mulai kolom X (index 24). Offset 8 (Kode Barang) => AF,
        // offset 9 (Nama Barang) => AG, offset 15 (Subtotal Item) => AM.
        $this->assertSame('BRG001', $sheet->getCell('AF4')->getValue());
        $this->assertSame('Barang Satu', $sheet->getCell('AG4')->getValue());
        $this->assertSame(50000.0, (float) $sheet->getCell('AM4')->getValue());

        $this->assertSame('BRG002', $sheet->getCell('AF5')->getValue());
        $this->assertSame('Barang Dua', $sheet->getCell('AG5')->getValue());
        $this->assertSame(100000.0, (float) $sheet->getCell('AM5')->getValue());

        // Baris detail tetap menyertakan no_tagihan dari header untuk penelusuran.
        $this->assertSame('AP-002', $sheet->getCell('Y4')->getValue());
        $this->assertSame('AP-002', $sheet->getCell('Y5')->getValue());
    }

    public function test_tagihan_tanpa_item_tetap_di_tabel_utama_tapi_tidak_di_detail(): void
    {
        $tagihan = $this->makeTagihan(3, 'AP-003', 'PT Vendor C', 'VN003', 'DEF', 'PIC C', []);

        $spreadsheet = $this->service->build(collect([$tagihan]));
        $sheet       = $spreadsheet->getSheetByName('Tagihan AP');

        $this->assertSame('AP-003', $sheet->getCell('B4')->getValue());
        $this->assertSame('Tidak ada data', $sheet->getCell('X4')->getValue());
    }

    public function test_tidak_ada_tagihan_sama_sekali_menampilkan_tidak_ada_data_di_kedua_tabel(): void
    {
        $spreadsheet = $this->service->build(collect());
        $sheet       = $spreadsheet->getSheetByName('Tagihan AP');

        $this->assertSame('Tidak ada data', $sheet->getCell('A4')->getValue());
        $this->assertSame('Tidak ada data', $sheet->getCell('X4')->getValue());
    }

    private function makeTagihan(
        int $id,
        string $noTagihan,
        string $namaVendor,
        string $kodeVendor,
        string $namaSingkatanPerusahaan,
        string $namaKaryawan,
        array $items,
    ): TagihanAp {
        $vendorAp = new VendorAp();
        $vendorAp->forceFill(['nama_vendor' => $namaVendor, 'kode_vendor' => $kodeVendor]);

        $perusahaan = new Perusahaan();
        $perusahaan->forceFill(['nama_singkatan_perusahaan' => $namaSingkatanPerusahaan]);

        $karyawan = new Karyawan();
        $karyawan->forceFill(['nama_karyawan' => $namaKaryawan]);

        $tagihan = new TagihanAp();
        $tagihan->forceFill([
            'id'                  => $id,
            'no_tagihan'          => $noTagihan,
            'no_invoice_vendor'   => 'INV-' . $id,
            'tanggal_tagihan'     => Carbon::parse('2026-07-01'),
            'tanggal_jatuh_tempo' => Carbon::parse('2026-08-01'),
            'no_po'               => 'PO-' . $id,
            'no_terima_barang'    => 'TB-' . $id,
            'subtotal'            => 100000,
            'ppn_masukan'         => 11000,
            'pph23'               => 2000,
            'total_tagihan'       => 109000,
            'total_pembayaran'    => 0,
            'sisa_tagihan'        => 109000,
            'status'              => 'DITERIMA',
            'approval_status'     => 'APPROVED',
            'keterangan'          => 'Test',
            'created_at'          => Carbon::parse('2026-07-01 10:00:00'),
        ]);
        $tagihan->setRelation('vendorAp', $vendorAp);
        $tagihan->setRelation('perusahaan', $perusahaan);
        $tagihan->setRelation('karyawan', $karyawan);
        $tagihan->setRelation('items', collect($items));
        $tagihan->setRelation('createdBy', null);

        return $tagihan;
    }

    private function makeItem(string $kode, string $nama, float $qty, float $harga): TagihanApItem
    {
        $item = new TagihanApItem();
        $item->forceFill([
            'kode_barang'             => $kode,
            'nama_barang'             => $nama,
            'qty'                     => $qty,
            'qty_po'                  => $qty,
            'satuan'                  => 'PCS',
            'harga_satuan'            => $harga,
            'ppn'                     => 0,
            'subtotal'                => $qty * $harga,
            'status_detail_terima_po' => 'LENGKAP',
            'qty_tolak'               => 0,
            'keterangan_tolak'        => null,
            'keterangan'              => null,
        ]);

        return $item;
    }
}
