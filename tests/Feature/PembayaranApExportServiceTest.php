<?php

namespace Tests\Feature;

use App\Domain\Finance\PembayaranAp\Services\PembayaranApExportService;
use App\Models\Karyawan;
use App\Models\PembayaranAp;
use App\Models\PembayaranApItem;
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
class PembayaranApExportServiceTest extends TestCase
{
    private PembayaranApExportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PembayaranApExportService();
    }

    public function test_workbook_punya_2_sheet_dengan_nama_yang_benar(): void
    {
        $spreadsheet = $this->service->build(collect());

        $this->assertSame(2, $spreadsheet->getSheetCount());
        $this->assertSame(['Bahan Baku', 'Non Bahan Baku'], $spreadsheet->getSheetNames());
    }

    public function test_voucher_bb_hanya_muncul_di_sheet_bahan_baku(): void
    {
        $bb  = $this->makeVoucher(1, 'BB', 'VBK-BB-01', [$this->makeAlokasi(1, 'PT Vendor BB', 'ABC', [
            $this->makeBarang('BRG001', 'Tepung Terigu', 10, 15000),
        ])]);
        $nbb = $this->makeVoucher(2, 'NBB', 'VBK-NBB-01', [$this->makeAlokasi(2, 'PT Vendor NBB', 'ABC', [])]);

        $spreadsheet = $this->service->build(collect([$bb, $nbb]));

        $sheetBb  = $spreadsheet->getSheetByName('Bahan Baku');
        $sheetNbb = $spreadsheet->getSheetByName('Non Bahan Baku');

        $this->assertSame('VBK-BB-01', $sheetBb->getCell('C4')->getValue());
        $this->assertSame('VBK-NBB-01', $sheetNbb->getCell('C4')->getValue());

        // Data BB tidak boleh bocor ke sheet NBB dan sebaliknya.
        $this->assertNotSame('VBK-NBB-01', $sheetBb->getCell('C4')->getValue());
        $this->assertNotSame('VBK-BB-01', $sheetNbb->getCell('C4')->getValue());
    }

    public function test_kategori_null_tidak_masuk_sheet_manapun(): void
    {
        $tanpaKategori = $this->makeVoucher(3, null, 'VBK-XX-01', []);

        $spreadsheet = $this->service->build(collect([$tanpaKategori]));

        $sheetBb  = $spreadsheet->getSheetByName('Bahan Baku');
        $sheetNbb = $spreadsheet->getSheetByName('Non Bahan Baku');

        $this->assertSame('Tidak ada data', $sheetBb->getCell('A4')->getValue());
        $this->assertSame('Tidak ada data', $sheetNbb->getCell('A4')->getValue());
    }

    public function test_detail_item_barang_ditulis_dengan_alokasi_voucher(): void
    {
        $voucher = $this->makeVoucher(4, 'BB', 'VBK-BB-02', [
            $this->makeAlokasi(4, 'PT Vendor Detail', 'XYZ', [
                $this->makeBarang('BRG010', 'Minyak Goreng', 5, 20000),
            ], totalTagihan: 1_000_000, sisaSebelum: 1_000_000, sisaSesudah: 900_000),
        ]);

        $spreadsheet = $this->service->build(collect([$voucher]));
        $sheet       = $spreadsheet->getSheetByName('Bahan Baku');

        // Detail table mulai kolom N. Offset 12 (Kode Barang) => Z, offset 13 (Nama Barang) => AA,
        // offset 22 (Dibayar) => AJ. Lihat urutan PembayaranApExportService::DETAIL_COLS.
        $this->assertSame('BRG010', $sheet->getCell('Z4')->getValue());
        $this->assertSame('Minyak Goreng', $sheet->getCell('AA4')->getValue());
        $this->assertEqualsWithDelta(100000.0, (float) $sheet->getCell('AJ4')->getValue(), 0.001); // Dibayar (1jt - 900rb)
        $this->assertSame(20000.0, (float) $sheet->getCell('AE4')->getValue()); // Harga Satuan
        $this->assertSame(100000.0, (float) $sheet->getCell('AG4')->getValue()); // Subtotal Item (5 x 20000)
    }

    public function test_tagihan_tanpa_item_barang_tetap_menulis_1_baris_alokasi(): void
    {
        $voucher = $this->makeVoucher(5, 'NBB', 'VBK-NBB-02', [
            $this->makeAlokasi(5, 'PT Vendor Kosong', 'ABC', []),
        ]);

        $spreadsheet = $this->service->build(collect([$voucher]));
        $sheet       = $spreadsheet->getSheetByName('Non Bahan Baku');

        $this->assertSame('', $sheet->getCell('Z4')->getValue());   // Kode Barang kosong
        $this->assertSame('', $sheet->getCell('AA4')->getValue());  // Nama Barang kosong
        $this->assertSame(1, (int) $sheet->getCell('N4')->getValue()); // Baris alokasi tetap ditulis
    }

    private function makeVoucher(int $id, ?string $kategori, string $noReferensi, array $items): PembayaranAp
    {
        $voucher = new PembayaranAp();
        $voucher->forceFill([
            'id'                 => $id,
            'kategori_voucher'   => $kategori,
            'no_referensi'       => $noReferensi,
            'tanggal_pembayaran' => Carbon::parse('2026-07-01'),
            'jumlah_pembayaran'  => collect($items)->sum(fn(PembayaranApItem $i) => (float) $i->jumlah_dialokasikan),
            'metode_pembayaran'  => 'TRANSFER',
            'keterangan'         => 'Test',
            'created_at'         => Carbon::parse('2026-07-01 10:00:00'),
        ]);
        $voucher->setRelation('items', collect($items));
        $voucher->setRelation('createdBy', null);

        return $voucher;
    }

    private function makeAlokasi(
        int $id,
        string $namaVendor,
        string $namaSingkatanPerusahaan,
        array $barangItems,
        float $totalTagihan = 500000,
        float $sisaSebelum = 500000,
        float $sisaSesudah = 0,
    ): PembayaranApItem {
        $vendorAp = new VendorAp();
        $vendorAp->forceFill(['nama_vendor' => $namaVendor]);

        $perusahaan = new Perusahaan();
        $perusahaan->forceFill(['nama_singkatan_perusahaan' => $namaSingkatanPerusahaan]);

        $karyawan = new Karyawan();
        $karyawan->forceFill(['nama_karyawan' => 'PIC Test']);

        $tagihan = new TagihanAp();
        $tagihan->forceFill([
            'no_tagihan'        => 'TGH-' . $id,
            'no_invoice_vendor' => 'INV-' . $id,
            'tanggal_tagihan'   => Carbon::parse('2026-06-01'),
            'no_po'             => 'PO-' . $id,
            'no_terima_barang'  => 'TB-' . $id,
            'total_tagihan'     => $totalTagihan,
        ]);
        $tagihan->setRelation('vendorAp', $vendorAp);
        $tagihan->setRelation('perusahaan', $perusahaan);
        $tagihan->setRelation('karyawan', $karyawan);
        $tagihan->setRelation('items', collect($barangItems));

        $item = new PembayaranApItem();
        $item->forceFill([
            'id'                  => $id,
            'jumlah_dialokasikan' => $sisaSebelum - $sisaSesudah,
            'sisa_sebelum'        => $sisaSebelum,
            'sisa_sesudah'        => $sisaSesudah,
        ]);
        $item->setRelation('tagihanAp', $tagihan);
        $item->setRelation('vendorAp', $vendorAp);

        return $item;
    }

    private function makeBarang(string $kode, string $nama, float $qty, float $harga): TagihanApItem
    {
        $barang = new TagihanApItem();
        $barang->forceFill([
            'kode_barang'  => $kode,
            'nama_barang'  => $nama,
            'qty'          => $qty,
            'qty_po'       => $qty,
            'satuan'       => 'PCS',
            'harga_satuan' => $harga,
            'ppn'          => 0,
            'subtotal'     => $qty * $harga,
            'keterangan'   => null,
        ]);

        return $barang;
    }
}
