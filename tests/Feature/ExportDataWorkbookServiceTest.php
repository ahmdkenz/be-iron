<?php

namespace Tests\Feature;

use App\Domain\Finance\AgingReport\Services\AgingReportService;
use App\Domain\Finance\ExportData\Services\ExportDataSheetWriter;
use App\Domain\Finance\ExportData\Services\ExportDataWorkbookService;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\JurnalPic\Services\JurnalPicService;
use App\Domain\Finance\KinerjaAr\Services\KinerjaArService;
use App\Domain\Finance\MutasiPiutang\Services\MutasiPiutangService;
use App\Domain\Finance\PembayaranAr\Services\RiwayatPembayaranService;
use App\Domain\Finance\PendapatanDiMuka\Services\PendapatanDiMukaService;
use App\Domain\Finance\RekapPembayaran\Services\RekapPembayaranService;
use App\Domain\Finance\RekeningKoran\Services\RekeningKoranUmumService;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Unit-style test murni in-memory (tanpa DB) — migrate:fresh rusak di project ini
 * (lihat PembayaranArServiceBuktiPathTest.php), jadi seluruh service laporan
 * di-mock dan relasi User di-set manual via setRelation().
 *
 * Mock-nya sengaja memakai willReturnCallback yang membaca $this->data /
 * menulis $this->capturedFilters, bukan willReturn per test — PHPUnit memakai
 * matcher PERTAMA yang cocok, sehingga stub yang didaftarkan ulang di dalam test
 * tidak akan pernah menimpa stub dari setUp().
 */
class ExportDataWorkbookServiceTest extends TestCase
{
    private ExportDataWorkbookService $service;
    private User $user;

    /** Payload yang dikembalikan tiap service laporan; ditimpa per test. */
    private array $data = [];

    /** Filter yang benar-benar diterima tiap service laporan. */
    private array $capturedFilters = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->data = [
            'aging_report'       => ['as_of_date' => '2026-07-28', 'summary' => [], 'rows' => []],
            'rekap_klien'        => [],
            'mutasi_piutang'     => ['periode_awal' => null, 'periode_akhir' => null, 'summary' => [], 'rows' => []],
            'rekening_koran'     => [],
            'rekening_koran_summary' => [],
            'riwayat_pembayaran' => new EloquentCollection(),
            'rekap_pembayaran'   => ['tanggal_dari' => null, 'tanggal_sampai' => null, 'summary' => [], 'rows' => []],
            'pendapatan_di_muka' => [],
            'jurnal_pic'         => new EloquentCollection(),
            'jurnal_pic_summary' => [],
            'kinerja_ar'         => ['periode_awal' => null, 'periode_akhir' => null, 'summary' => [], 'rows' => []],
        ];

        $agingReportService = $this->createMock(AgingReportService::class);
        $agingReportService->method('getReport')->willReturnCallback($this->stub('aging_report'));

        $invoiceService = $this->createMock(InvoiceService::class);
        $invoiceService->method('getRekapKlien')->willReturnCallback($this->stub('rekap_klien'));

        $mutasiPiutangService = $this->createMock(MutasiPiutangService::class);
        $mutasiPiutangService->method('getReport')->willReturnCallback($this->stub('mutasi_piutang'));

        $rekeningKoranService = $this->createMock(RekeningKoranUmumService::class);
        $rekeningKoranService->method('getAllRows')->willReturnCallback($this->stub('rekening_koran'));
        $rekeningKoranService->method('getSummary')->willReturnCallback($this->stub('rekening_koran_summary'));

        $riwayatPembayaranService = $this->createMock(RiwayatPembayaranService::class);
        $riwayatPembayaranService->method('getAll')->willReturnCallback($this->stub('riwayat_pembayaran'));

        $rekapPembayaranService = $this->createMock(RekapPembayaranService::class);
        $rekapPembayaranService->method('getReport')->willReturnCallback($this->stub('rekap_pembayaran'));

        $pendapatanDiMukaService = $this->createMock(PendapatanDiMukaService::class);
        $pendapatanDiMukaService->method('getAllFormatted')->willReturnCallback($this->stub('pendapatan_di_muka'));

        $jurnalPicService = $this->createMock(JurnalPicService::class);
        $jurnalPicService->method('getJurnalAll')->willReturnCallback($this->stub('jurnal_pic'));
        $jurnalPicService->method('getSummary')->willReturnCallback($this->stub('jurnal_pic_summary'));

        $kinerjaArService = $this->createMock(KinerjaArService::class);
        $kinerjaArService->method('getReport')->willReturnCallback($this->stub('kinerja_ar'));

        $this->service = new ExportDataWorkbookService(
            new ExportDataSheetWriter(),
            $agingReportService,
            $invoiceService,
            $mutasiPiutangService,
            $rekeningKoranService,
            $riwayatPembayaranService,
            $rekapPembayaranService,
            $pendapatanDiMukaService,
            $jurnalPicService,
            $kinerjaArService,
        );

        // Relasi di-set manual supaya ArFilterScope/RoleHelper tidak menyentuh DB.
        $this->user = new User();
        $this->user->forceFill(['id' => 1, 'username' => 'admin']);
        $this->user->setRelation('karyawan', null);
        $this->user->setRelation('roles', collect());
    }

    public function test_satu_laporan_menghasilkan_workbook_satu_sheet(): void
    {
        $spreadsheet = $this->service->build(['rekap_klien'], [], $this->user);

        $this->assertSame(1, $spreadsheet->getSheetCount());
        $this->assertSame(['Rekap Per Klien'], $spreadsheet->getSheetNames());
    }

    public function test_beberapa_laporan_menghasilkan_satu_sheet_per_laporan_sesuai_urutan_pilihan(): void
    {
        $spreadsheet = $this->service->build(
            ['kinerja_ar', 'aging_report', 'rekening_koran'],
            [],
            $this->user,
        );

        $this->assertSame(3, $spreadsheet->getSheetCount());
        $this->assertSame(['Kinerja AR', 'Aging Report', 'Rekening Koran'], $spreadsheet->getSheetNames());
    }

    public function test_semua_sembilan_laporan_bisa_diexport_sekaligus(): void
    {
        $spreadsheet = $this->service->build(
            array_keys(ExportDataWorkbookService::REPORTS),
            [],
            $this->user,
        );

        $this->assertSame(9, $spreadsheet->getSheetCount());
        $this->assertSame(
            array_values(ExportDataWorkbookService::REPORTS),
            $spreadsheet->getSheetNames(),
        );
    }

    public function test_laporan_duplikat_tidak_membuat_sheet_ganda(): void
    {
        $spreadsheet = $this->service->build(['kinerja_ar', 'kinerja_ar'], [], $this->user);

        $this->assertSame(1, $spreadsheet->getSheetCount());
    }

    public function test_aging_report_menulis_ringkasan_dan_detail_berdampingan_di_satu_sheet(): void
    {
        $this->data['aging_report'] = [
            'as_of_date' => '2026-07-28',
            'summary'    => [
                'current' => 100, 'hari_1_30' => 200, 'hari_31_60' => 0,
                'hari_61_90' => 0, 'hari_91_plus' => 0, 'total' => 300,
            ],
            'rows'       => [[
                'kode_klien'   => 'K001',
                'nama_klien'   => 'PT Satu',
                'pic_ar'       => 'Budi',
                'perusahaan'   => 'ABC',
                'current'      => 100,
                'hari_1_30'    => 200,
                'hari_31_60'   => 0,
                'hari_61_90'   => 0,
                'hari_91_plus' => 0,
                'total'        => 300,
                'details'      => [[
                    'no_invoice'          => 'INV-001',
                    'tanggal_invoice'     => '2026-06-01',
                    'tanggal_jatuh_tempo' => '2026-07-01',
                    'umur_invoice'        => 57,
                    'hari_terlambat'      => 27,
                    'bucket'              => 'hari_1_30',
                    'total_tagihan'       => 500,
                    'total_pembayaran'    => 200,
                    'sisa_tagihan'        => 300,
                    'status'              => 'SEBAGIAN',
                    'pic_ar'              => 'Budi',
                    'perusahaan'          => 'ABC',
                ]],
            ]],
        ];

        $spreadsheet = $this->service->build(['aging_report'], [], $this->user);

        $this->assertSame(1, $spreadsheet->getSheetCount());
        $sheet = $spreadsheet->getSheetByName('Aging Report');

        // Blok ringkasan: 11 kolom (A..K), 1 kolom pemisah (L),
        // lalu blok detail mulai kolom M.
        $this->assertSame('AGING REPORT - RINGKASAN PER KLIEN', $sheet->getCell('A1')->getValue());
        $this->assertSame('AGING REPORT - DETAIL INVOICE', $sheet->getCell('M1')->getValue());

        $this->assertSame('PT Satu', $sheet->getCell('C5')->getValue());
        $this->assertSame(300.0, (float) $sheet->getCell('K5')->getValue());

        $this->assertSame('INV-001', $sheet->getCell('P5')->getValue());
        $this->assertSame('1-30 Hari', $sheet->getCell('U5')->getValue());
        $this->assertSame(300.0, (float) $sheet->getCell('X5')->getValue());

        // Baris total ringkasan ada tepat di bawah baris data.
        $this->assertSame('TOTAL', $sheet->getCell('C6')->getValue());
        $this->assertSame(300.0, (float) $sheet->getCell('K6')->getValue());
    }

    public function test_mutasi_piutang_juga_satu_sheet_dengan_dua_blok(): void
    {
        $this->data['mutasi_piutang'] = [
            'periode_awal'  => '2026-07-01',
            'periode_akhir' => '2026-07-31',
            'summary'       => ['saldo_awal' => 1000, 'invoice_masuk' => 500, 'pembayaran' => 300, 'saldo_akhir' => 1200],
            'rows'          => [[
                'kode_klien'    => 'K001',
                'nama_klien'    => 'PT Satu',
                'perusahaan'    => 'ABC',
                'pic_ar'        => 'Budi',
                'saldo_awal'    => 1000,
                'invoice_masuk' => 500,
                'pembayaran'    => 300,
                'saldo_akhir'   => 1200,
                'details'       => [[
                    'tanggal'    => '2026-07-05',
                    'label'      => 'Invoice',
                    'no_dokumen' => 'INV-001',
                    'no_invoice' => 'INV-001',
                    'debit'      => 500,
                    'kredit'     => 0,
                    'saldo'      => 1500,
                    'keterangan' => 'Invoice masuk',
                ]],
            ]],
        ];

        $sheet = $this->service->build(['mutasi_piutang'], [], $this->user)->getSheetByName('Mutasi Piutang');

        // Ringkasan 9 kolom (A..I), pemisah J, detail mulai K.
        $this->assertSame('MUTASI PIUTANG - RINGKASAN PER KLIEN', $sheet->getCell('A1')->getValue());
        $this->assertSame('MUTASI PIUTANG - DETAIL MUTASI', $sheet->getCell('K1')->getValue());
        $this->assertSame(1200.0, (float) $sheet->getCell('I5')->getValue());
        $this->assertSame('INV-001', $sheet->getCell('P5')->getValue());
        $this->assertSame(500.0, (float) $sheet->getCell('R5')->getValue());
    }

    public function test_rekap_klien_menulis_baris_total(): void
    {
        $this->data['rekap_klien'] = [
            [
                'kode_klien' => 'K001', 'nama_klien' => 'PT Satu', 'pic_ar' => 'Budi', 'perusahaan' => 'ABC',
                'total_invoice' => 2, 'total_tagihan' => 1000, 'total_pembayaran' => 400,
                'sisa_tagihan' => 600, 'overdue_amount' => 100, 'collection_rate' => 40,
                'draft' => 0, 'terkirim' => 1, 'sebagian' => 1, 'lunas' => 0,
            ],
            [
                'kode_klien' => 'K002', 'nama_klien' => 'PT Dua', 'pic_ar' => 'Sari', 'perusahaan' => 'ABC',
                'total_invoice' => 1, 'total_tagihan' => 500, 'total_pembayaran' => 500,
                'sisa_tagihan' => 0, 'overdue_amount' => 0, 'collection_rate' => 100,
                'draft' => 0, 'terkirim' => 0, 'sebagian' => 0, 'lunas' => 1,
            ],
        ];

        $sheet = $this->service->build(['rekap_klien'], [], $this->user)->getSheetByName('Rekap Per Klien');

        $this->assertSame('PT Satu', $sheet->getCell('C5')->getValue());
        $this->assertSame('PT Dua', $sheet->getCell('C6')->getValue());
        $this->assertSame('40%', $sheet->getCell('K5')->getValue());

        // Baris 7 = total: 1000 + 500 tagihan, 400 + 500 pembayaran.
        $this->assertSame('TOTAL', $sheet->getCell('C7')->getValue());
        $this->assertSame(1500.0, (float) $sheet->getCell('G7')->getValue());
        $this->assertSame(900.0, (float) $sheet->getCell('H7')->getValue());
    }

    public function test_rekening_koran_menulis_kolom_status_posting(): void
    {
        $this->data['rekening_koran'] = [[
            'no_referensi'     => 'TRX-001',
            'tanggal'          => '2026-07-05',
            'waktu_transaksi'  => '10:15',
            'bank_type'        => 'BCA',
            'dk'               => 'K',
            'mutasi'           => 1_000_000,
            'saldo'            => 5_000_000,
            'keterangan'       => 'Transfer masuk',
            'status_posting_1' => 'MATCHED',
            'no_dokumen_ar'    => 'INV-001',
            'selisih'          => 0,
            'status_posting_2' => 'POSTED',
            'pic_ar'           => 'Budi',
            'posted_by'        => 'Sari',
            'posted_at'        => '2026-07-06 09:00:00',
        ]];
        $this->data['rekening_koran_summary'] = [
            'total_transaksi' => 1, 'total_matched' => 1, 'total_unmatched' => 0,
            'total_posted' => 1, 'total_pending' => 0, 'total_mutasi_masuk' => 1_000_000,
        ];

        $sheet = $this->service->build(['rekening_koran'], [], $this->user)->getSheetByName('Rekening Koran');

        $this->assertSame('TRX-001', $sheet->getCell('B5')->getValue());
        $this->assertSame(1_000_000.0, (float) $sheet->getCell('G5')->getValue());
        $this->assertSame('MATCHED', $sheet->getCell('J5')->getValue());
        $this->assertSame('POSTED', $sheet->getCell('M5')->getValue());
    }

    public function test_laporan_tanpa_data_menulis_tidak_ada_data(): void
    {
        $sheet = $this->service->build(['kinerja_ar'], [], $this->user)->getSheetByName('Kinerja AR');

        $this->assertSame('Tidak ada data', $sheet->getCell('A5')->getValue());
    }

    public function test_filter_rekening_koran_diteruskan_ke_service_laporan(): void
    {
        $this->service->build(['rekening_koran'], [
            'tanggal_dari'     => '2026-07-01',
            'tanggal_sampai'   => '2026-07-31',
            'pic_ar_id'        => 7,
            'bank_type'        => 'BCA',
            'dk'               => 'K',
            'status_posting_1' => 'MATCHED',
            'status_posting_2' => 'POSTED',
        ], $this->user);

        // Filter periode umum Export Data dipetakan ke nama filter Rekening Koran.
        $captured = $this->capturedFilters['rekening_koran'];
        $this->assertSame('2026-07-01', $captured['periode_awal']);
        $this->assertSame('2026-07-31', $captured['periode_akhir']);
        $this->assertSame(7, $captured['pic_ar_id']);
        $this->assertSame('BCA', $captured['bank_type']);
        $this->assertSame('K', $captured['dk']);
        $this->assertSame('MATCHED', $captured['status_posting_1']);
        $this->assertSame('POSTED', $captured['status_posting_2']);
    }

    public function test_filter_periode_diteruskan_ke_laporan_yang_memakai_periode_awal_akhir(): void
    {
        $this->service->build(['kinerja_ar'], [
            'tanggal_dari'   => '2026-07-01',
            'tanggal_sampai' => '2026-07-31',
            'segment'        => 'B2B',
        ], $this->user);

        $captured = $this->capturedFilters['kinerja_ar'];
        $this->assertSame('2026-07-01', $captured['periode_awal']);
        $this->assertSame('2026-07-31', $captured['periode_akhir']);
        $this->assertSame('B2B', $captured['segment']);
    }

    public function test_filter_kosong_tidak_diteruskan_ke_laporan_ber_scope(): void
    {
        $this->service->build(['rekap_klien'], [
            'segment'     => 'ALL',
            'klien_ar_id' => null,
        ], $this->user);

        $captured = $this->capturedFilters['rekap_klien'];
        $this->assertArrayNotHasKey('klien_ar_id', $captured);
        $this->assertSame('ALL', $captured['segment']);
    }

    public function test_report_key_tidak_dikenal_ditolak(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->build(['laporan_hantu'], [], $this->user);
    }

    public function test_daftar_laporan_kosong_ditolak(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->build([], [], $this->user);
    }

    /** Stub yang mencatat filter masuk lalu mengembalikan payload dari $this->data. */
    private function stub(string $key): callable
    {
        return function (array $filters = []) use ($key) {
            $this->capturedFilters[$key] = $filters;

            return $this->data[$key];
        };
    }
}
