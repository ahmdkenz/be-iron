<?php

namespace Tests\Feature;

use App\Domain\Finance\OpeningBalanceAp\Services\OpeningBalanceApExportService;
use App\Models\Karyawan;
use App\Models\OpeningBalanceApDetail;
use App\Models\OpeningBalanceApDetailItem;
use App\Models\Perusahaan;
use App\Models\TagihanAp;
use App\Models\User;
use App\Models\VendorAp;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Unit-style test murni in-memory (tanpa DB) — migrate:fresh rusak di project
 * ini (lihat PembayaranApExportServiceTest.php), jadi relasi Eloquent
 * di-mock via setRelation() bukan query sungguhan.
 */
class OpeningBalanceApExportServiceTest extends TestCase
{
    private OpeningBalanceApExportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OpeningBalanceApExportService();
    }

    public function test_workbook_punya_2_sheet_dengan_nama_yang_benar(): void
    {
        $spreadsheet = $this->service->build(collect());

        $this->assertSame(2, $spreadsheet->getSheetCount());
        $this->assertSame(['Opening Balance AP', 'Detail Opening Balance AP'], $spreadsheet->getSheetNames());
    }

    public function test_rekap_dan_detail_kosong_menulis_tidak_ada_data(): void
    {
        $spreadsheet = $this->service->build(collect());
        $rekapSheet  = $spreadsheet->getSheetByName('Opening Balance AP');
        $detailSheet = $spreadsheet->getSheetByName('Detail Opening Balance AP');

        $this->assertSame('Tidak ada data', $rekapSheet->getCell('A4')->getValue());
        $this->assertSame('Tidak ada data', $detailSheet->getCell('A4')->getValue());
    }

    public function test_rekap_menulis_data_ob_dan_audit_trail(): void
    {
        $tagihan = $this->makeOpeningBalance(1, 'OB-AP-01', [], submittedName: 'Budi', approvedName: 'Sari');

        $spreadsheet = $this->service->build(collect([$tagihan]));
        $sheet       = $spreadsheet->getSheetByName('Opening Balance AP');

        $this->assertSame('OB-AP-01', $sheet->getCell('B4')->getValue());
        $this->assertSame(1_000_000.0, (float) $sheet->getCell('I4')->getValue()); // Saldo Awal
        $this->assertSame('Budi', $sheet->getCell('P4')->getValue()); // Diajukan Oleh
        $this->assertSame('Sari', $sheet->getCell('R4')->getValue()); // Disetujui Oleh
    }

    public function test_detail_invoice_asal_dengan_item_barang_ditulis_per_baris(): void
    {
        $detail  = $this->makeDetail('INV-001', [
            $this->makeItem('BRG001', 'Tepung Terigu', 10, 15000),
        ]);
        $tagihan = $this->makeOpeningBalance(2, 'OB-AP-02', [$detail]);

        $spreadsheet = $this->service->build(collect([$tagihan]));
        $sheet       = $spreadsheet->getSheetByName('Detail Opening Balance AP');

        // Sheet detail mulai kolom A. Offset 8 (Kode Barang) => I, offset 9 (Nama Barang) => J.
        $this->assertSame('INV-001', $sheet->getCell('D4')->getValue());
        $this->assertSame('BRG001', $sheet->getCell('I4')->getValue());
        $this->assertSame('Tepung Terigu', $sheet->getCell('J4')->getValue());
        $this->assertSame(150000.0, (float) $sheet->getCell('N4')->getValue()); // Subtotal Item
    }

    public function test_detail_tanpa_item_barang_tetap_menulis_1_baris(): void
    {
        $detail  = $this->makeDetail('INV-002', []);
        $tagihan = $this->makeOpeningBalance(3, 'OB-AP-03', [$detail]);

        $spreadsheet = $this->service->build(collect([$tagihan]));
        $sheet       = $spreadsheet->getSheetByName('Detail Opening Balance AP');

        $this->assertSame(1, (int) $sheet->getCell('A4')->getValue());
        $this->assertSame('INV-002', $sheet->getCell('D4')->getValue());
        $this->assertSame('', $sheet->getCell('I4')->getValue()); // Kode Barang kosong
        $this->assertSame('', $sheet->getCell('J4')->getValue()); // Nama Barang kosong
    }

    private function makeOpeningBalance(
        int $id,
        string $noTagihan,
        array $details,
        ?string $submittedName = null,
        ?string $approvedName = null,
    ): TagihanAp {
        $vendorAp = new VendorAp();
        $vendorAp->forceFill(['nama_vendor' => 'PT Vendor OB', 'kode_vendor' => 'VN001']);

        $perusahaan = new Perusahaan();
        $perusahaan->forceFill(['nama_singkatan_perusahaan' => 'ABC']);

        $karyawan = new Karyawan();
        $karyawan->forceFill(['nama_karyawan' => 'PIC Test']);

        $tagihan = new TagihanAp();
        $tagihan->forceFill([
            'id'                 => $id,
            'no_tagihan'         => $noTagihan,
            'tanggal_tagihan'    => Carbon::parse('2026-07-01'),
            'tanggal_jatuh_tempo' => Carbon::parse('2026-07-31'),
            'total_tagihan'      => 1_000_000,
            'total_pembayaran'   => 0,
            'total_penyesuaian'  => 0,
            'sisa_tagihan'       => 1_000_000,
            'status'             => 'DITERIMA',
            'approval_status'    => 'APPROVED',
            'keterangan'         => 'Opening Balance AP',
            'submitted_at'       => Carbon::parse('2026-06-25 09:00:00'),
            'approved_at'        => Carbon::parse('2026-06-26 10:00:00'),
            'created_at'         => Carbon::parse('2026-06-25 08:00:00'),
        ]);
        $tagihan->setRelation('vendorAp', $vendorAp);
        $tagihan->setRelation('perusahaan', $perusahaan);
        $tagihan->setRelation('karyawan', $karyawan);
        $tagihan->setRelation('openingBalanceApDetails', collect($details));
        $tagihan->setRelation('submittedBy', $submittedName ? $this->makeUser($submittedName) : null);
        $tagihan->setRelation('approvedBy', $approvedName ? $this->makeUser($approvedName) : null);
        $tagihan->setRelation('rejectedBy', null);
        $tagihan->setRelation('createdBy', null);

        return $tagihan;
    }

    private function makeUser(string $username): User
    {
        $user = new User();
        $user->forceFill(['username' => $username]);
        $user->setRelation('karyawan', null);

        return $user;
    }

    private function makeDetail(string $noInvoiceAsal, array $items): OpeningBalanceApDetail
    {
        $detail = new OpeningBalanceApDetail();
        $detail->forceFill([
            'no_invoice_asal'      => $noInvoiceAsal,
            'tanggal_invoice_asal' => Carbon::parse('2026-06-01'),
            'deskripsi'            => 'Saldo awal invoice',
            'jumlah_tagihan_asal'  => collect($items)->sum(fn(OpeningBalanceApDetailItem $i) => (float) $i->subtotal) ?: 500000,
            'sisa_tagihan_asal'    => 500000,
            'keterangan'           => null,
        ]);
        $detail->setRelation('items', collect($items));

        return $detail;
    }

    private function makeItem(string $kode, string $nama, float $qty, float $harga): OpeningBalanceApDetailItem
    {
        $item = new OpeningBalanceApDetailItem();
        $item->forceFill([
            'kode_barang'  => $kode,
            'nama_barang'  => $nama,
            'qty'          => $qty,
            'satuan'       => 'PCS',
            'harga_satuan' => $harga,
            'subtotal'     => $qty * $harga,
            'keterangan'   => null,
        ]);
        $item->setRelation('barang', null);

        return $item;
    }
}
