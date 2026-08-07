<?php

namespace App\Domain\Finance\ExportData\Services;

use App\Domain\Finance\AgingReport\Services\AgingReportService;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\JurnalPic\Services\JurnalPicService;
use App\Domain\Finance\KinerjaAr\Services\KinerjaArService;
use App\Domain\Finance\MutasiPiutang\Services\MutasiPiutangService;
use App\Domain\Finance\PembayaranAr\Services\RiwayatPembayaranService;
use App\Domain\Finance\PendapatanDiMuka\Services\PendapatanDiMukaService;
use App\Domain\Finance\RekapPembayaran\Services\RekapPembayaranService;
use App\Domain\Finance\RekeningKoran\Services\RekeningKoranUmumService;
use App\Models\PembayaranAr;
use App\Models\User;
use App\Support\Helpers\ArFilterScope;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Pusat export Finance: memetakan report key → sumber data resmi → satu
 * worksheet.
 *
 * Prinsipnya:
 * - Satu laporan = satu sheet, urutannya mengikuti urutan pilihan user.
 * - Datanya diambil dari service laporan yang sama dengan halaman laporan
 *   satuan, jadi scope & otorisasinya identik (termasuk ArFilterScope).
 * - Laporan yang punya ringkasan + detail (Aging, Mutasi Piutang) tetap satu
 *   sheet: dua blok ditulis berdampingan, bukan dipecah jadi 2 sheet.
 */
class ExportDataWorkbookService
{
    /** report key => nama sheet. Sekaligus jadi whitelist key yang valid. */
    public const REPORTS = [
        'aging_report'       => 'Aging Report',
        'rekap_klien'        => 'Rekap Per Klien',
        'mutasi_piutang'     => 'Mutasi Piutang',
        'rekening_koran'     => 'Rekening Koran',
        'riwayat_pembayaran' => 'Riwayat Pembayaran',
        'rekap_pembayaran'   => 'Rekap Pembayaran',
        'pendapatan_di_muka' => 'Pendapatan di Muka',
        'jurnal_pic'         => 'Jurnal PIC',
        'kinerja_ar'         => 'Kinerja AR',
    ];

    private const THEMES = [
        'aging_report'       => ['title' => 'FFB71C1C', 'header' => 'FFC62828', 'band' => 'FFFFEBEE'],
        'rekap_klien'        => ['title' => 'FF4A148C', 'header' => 'FF6A1B9A', 'band' => 'FFF3E5F5'],
        'mutasi_piutang'     => ['title' => 'FF4527A0', 'header' => 'FF512DA8', 'band' => 'FFEDE7F6'],
        'rekening_koran'     => ['title' => 'FF01579B', 'header' => 'FF0277BD', 'band' => 'FFE1F5FE'],
        'riwayat_pembayaran' => ['title' => 'FF1B5E20', 'header' => 'FF2E7D32', 'band' => 'FFE8F5E9'],
        'rekap_pembayaran'   => ['title' => 'FF0D47A1', 'header' => 'FF1565C0', 'band' => 'FFE3F2FD'],
        'pendapatan_di_muka' => ['title' => 'FF006064', 'header' => 'FF00838F', 'band' => 'FFE0F7FA'],
        'jurnal_pic'         => ['title' => 'FF4E342E', 'header' => 'FF6D4C41', 'band' => 'FFEFEBE9'],
        'kinerja_ar'         => ['title' => 'FF00695C', 'header' => 'FF00796B', 'band' => 'FFE0F2F1'],
    ];

    private const BUCKET_LABELS = [
        'current'      => 'Belum JT',
        'hari_1_30'    => '1-30 Hari',
        'hari_31_60'   => '31-60 Hari',
        'hari_61_90'   => '61-90 Hari',
        'hari_91_plus' => '>90 Hari',
    ];

    public function __construct(
        private readonly ExportDataSheetWriter $writer,
        private readonly AgingReportService $agingReportService,
        private readonly InvoiceService $invoiceService,
        private readonly MutasiPiutangService $mutasiPiutangService,
        private readonly RekeningKoranUmumService $rekeningKoranService,
        private readonly RiwayatPembayaranService $riwayatPembayaranService,
        private readonly RekapPembayaranService $rekapPembayaranService,
        private readonly PendapatanDiMukaService $pendapatanDiMukaService,
        private readonly JurnalPicService $jurnalPicService,
        private readonly KinerjaArService $kinerjaArService,
    ) {}

    /**
     * @param  string[]  $reportKeys  Urutan sheet mengikuti urutan array ini.
     */
    public function build(array $reportKeys, array $filters, User $user): Spreadsheet
    {
        $reportKeys = array_values(array_unique($reportKeys));

        if ($reportKeys === []) {
            throw new InvalidArgumentException('Minimal satu laporan harus dipilih.');
        }

        foreach ($reportKeys as $key) {
            if (!isset(self::REPORTS[$key])) {
                throw new InvalidArgumentException("Laporan \"{$key}\" tidak dikenal.");
            }
        }

        $spreadsheet = new Spreadsheet();

        foreach ($reportKeys as $index => $key) {
            $sheet = $index === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $sheet->setTitle(self::REPORTS[$key]);

            $this->writeSections($sheet, $this->sectionsFor($key, $filters, $user));
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /** @param  array<int, array>  $sections */
    private function writeSections(Worksheet $sheet, array $sections): void
    {
        $column = 1;

        foreach ($sections as $section) {
            if ($column > 1) {
                $this->writer->writeGap($sheet, $column);
                $column++;
            }

            $column += $this->writer->write($sheet, $section, $column);
        }

        $sheet->freezePane('A' . ExportDataSheetWriter::ROW_FIRST_DATA);
    }

    /** @return array<int, array> Satu atau dua blok tabel untuk sheet ini. */
    private function sectionsFor(string $key, array $filters, User $user): array
    {
        return match ($key) {
            'aging_report'       => $this->agingSections($filters, $user),
            'rekap_klien'        => [$this->rekapKlienSection($filters, $user)],
            'mutasi_piutang'     => $this->mutasiSections($filters, $user),
            'rekening_koran'     => [$this->rekeningKoranSection($filters)],
            'riwayat_pembayaran' => [$this->riwayatPembayaranSection($filters, $user)],
            'rekap_pembayaran'   => [$this->rekapPembayaranSection($filters, $user)],
            'pendapatan_di_muka' => [$this->pendapatanDiMukaSection($filters)],
            'jurnal_pic'         => [$this->jurnalPicSection($filters, $user)],
            'kinerja_ar'         => [$this->kinerjaArSection($filters)],
        };
    }

    // ─── Aging Report ────────────────────────────────────────────────────

    private function agingSections(array $filters, User $user): array
    {
        $scoped = $this->scoped([
            'as_of_date'  => $filters['as_of_date'] ?? null,
            'klien_ar_id' => $filters['klien_ar_id'] ?? null,
            'segment'     => $filters['segment'] ?? null,
        ], $user);

        $report = $this->agingReportService->getReport($scoped);
        $rows   = $report['rows'] ?? [];

        $summarySection = [
            'title'   => 'AGING REPORT - RINGKASAN PER KLIEN',
            'meta'    => 'Per Tanggal: ' . ($report['as_of_date'] ?? '-') . '   |   Total Klien: ' . count($rows),
            'theme'   => self::THEMES['aging_report'],
            'columns' => [
                ['label' => 'No',          'width' => 5,  'type' => 'int', 'align' => 'center'],
                ['label' => 'Kode Klien',  'width' => 16],
                ['label' => 'Nama Klien',  'width' => 30],
                ['label' => 'PIC AR',      'width' => 20],
                ['label' => 'Entitas',     'width' => 14],
                ['label' => 'Belum JT',    'width' => 18, 'type' => 'currency'],
                ['label' => '1-30 Hari',   'width' => 18, 'type' => 'currency'],
                ['label' => '31-60 Hari',  'width' => 18, 'type' => 'currency'],
                ['label' => '61-90 Hari',  'width' => 18, 'type' => 'currency'],
                ['label' => '>90 Hari',    'width' => 18, 'type' => 'currency'],
                ['label' => 'Total',       'width' => 20, 'type' => 'currency'],
            ],
            'rows'    => [],
            'total'   => null,
        ];

        $detailSection = [
            'title'   => 'AGING REPORT - DETAIL INVOICE',
            'meta'    => 'Per Tanggal: ' . ($report['as_of_date'] ?? '-'),
            'theme'   => self::THEMES['aging_report'],
            'columns' => [
                ['label' => 'No',             'width' => 5,  'type' => 'int', 'align' => 'center'],
                ['label' => 'Kode Klien',     'width' => 14],
                ['label' => 'Nama Klien',     'width' => 26],
                ['label' => 'No Invoice',     'width' => 22],
                ['label' => 'Tgl Invoice',    'width' => 13, 'align' => 'center'],
                ['label' => 'Jatuh Tempo',    'width' => 13, 'align' => 'center'],
                ['label' => 'Umur (hari)',    'width' => 11, 'type' => 'int'],
                ['label' => 'Hari Terlambat', 'width' => 13, 'type' => 'int'],
                ['label' => 'Bucket',         'width' => 14, 'align' => 'center'],
                ['label' => 'Total Tagihan',  'width' => 18, 'type' => 'currency'],
                ['label' => 'Total Bayar',    'width' => 18, 'type' => 'currency'],
                ['label' => 'Sisa Tagihan',   'width' => 18, 'type' => 'currency'],
                ['label' => 'Status',         'width' => 16, 'align' => 'center'],
                ['label' => 'PIC AR',         'width' => 20],
                ['label' => 'Entitas',        'width' => 12],
            ],
            'rows'    => [],
            'total'   => null,
        ];

        $detailRows = [];
        foreach ($rows as $index => $row) {
            $summarySection['rows'][] = [
                $index + 1,
                $row['kode_klien'] ?? '',
                $row['nama_klien'] ?? '',
                $row['pic_ar'] ?? '',
                $row['perusahaan'] ?? '',
                $row['current'] ?? 0,
                $row['hari_1_30'] ?? 0,
                $row['hari_31_60'] ?? 0,
                $row['hari_61_90'] ?? 0,
                $row['hari_91_plus'] ?? 0,
                $row['total'] ?? 0,
            ];

            foreach (($row['details'] ?? []) as $detail) {
                $detailRows[] = [
                    count($detailRows) + 1,
                    $row['kode_klien'] ?? '',
                    $row['nama_klien'] ?? '',
                    $detail['no_invoice'] ?? '',
                    $detail['tanggal_invoice'] ?? '',
                    $detail['tanggal_jatuh_tempo'] ?? '',
                    $detail['umur_invoice'] ?? 0,
                    $detail['hari_terlambat'] ?? 0,
                    self::BUCKET_LABELS[$detail['bucket'] ?? ''] ?? ($detail['bucket'] ?? ''),
                    $detail['total_tagihan'] ?? 0,
                    $detail['total_pembayaran'] ?? 0,
                    $detail['sisa_tagihan'] ?? 0,
                    $detail['status'] ?? '',
                    $detail['pic_ar'] ?? '',
                    $detail['perusahaan'] ?? '',
                ];
            }
        }

        $summary = $report['summary'] ?? [];

        $summarySection['total'] = [
            '', '', 'TOTAL', '', '',
            $summary['current'] ?? 0,
            $summary['hari_1_30'] ?? 0,
            $summary['hari_31_60'] ?? 0,
            $summary['hari_61_90'] ?? 0,
            $summary['hari_91_plus'] ?? 0,
            $summary['total'] ?? 0,
        ];

        $detailSection['rows']  = $detailRows;
        $detailSection['meta'] .= '   |   Total Invoice: ' . count($detailRows);
        $detailSection['total'] = [
            '', '', '', '', '', '', '', '', 'TOTAL',
            $this->sumColumn($detailRows, 9),
            $this->sumColumn($detailRows, 10),
            $this->sumColumn($detailRows, 11),
            '', '', '',
        ];

        return [$summarySection, $detailSection];
    }

    // ─── Rekap Per Klien ─────────────────────────────────────────────────

    private function rekapKlienSection(array $filters, User $user): array
    {
        $scoped = $this->scoped([
            'klien_ar_id'   => $filters['klien_ar_id'] ?? null,
            'periode_bulan' => $filters['periode_bulan'] ?? null,
            'periode_tahun' => $filters['periode_tahun'] ?? null,
            'segment'       => $filters['segment'] ?? null,
        ], $user);

        $rows = $this->invoiceService->getRekapKlien($scoped);

        $dataRows = [];
        foreach ($rows as $index => $row) {
            $dataRows[] = [
                $index + 1,
                $row['nama_klien'] ?? '',
                // Klien B2B (PT) tidak punya 1 resto tunggal (lihat catatan sama di
                // InvoiceController::rekapKlienRowValues(), kedua tempat ini sengaja disamakan).
                $row['kode_resto'] ?? '-',
                $row['nama_resto'] ?? '-',
                $row['pic_ar'] ?? '',
                $row['perusahaan'] ?? '',
                $row['total_invoice'] ?? 0,
                $row['total_tagihan'] ?? 0,
                $row['total_pembayaran'] ?? 0,
                $row['sisa_tagihan'] ?? 0,
                $row['overdue_amount'] ?? 0,
                ($row['collection_rate'] ?? 0) . '%',
                $row['draft'] ?? 0,
                $row['terkirim'] ?? 0,
                $row['sebagian'] ?? 0,
                $row['lunas'] ?? 0,
            ];
        }

        return [
            'title'   => 'REKAP PIUTANG PER KLIEN',
            'meta'    => 'Periode: ' . $this->periodeBulanLabel($filters)
                . '   |   Segment: ' . $this->segmentLabel($filters)
                . '   |   Total Klien: ' . count($dataRows),
            'theme'   => self::THEMES['rekap_klien'],
            'columns' => [
                ['label' => 'No',              'width' => 5,  'type' => 'int', 'align' => 'center'],
                ['label' => 'Nama Klien',      'width' => 30],
                ['label' => 'Kode Resto',      'width' => 16],
                ['label' => 'Nama Resto',      'width' => 24],
                ['label' => 'PIC AR',          'width' => 20],
                ['label' => 'Entitas',         'width' => 14],
                ['label' => 'Jml Invoice',     'width' => 12, 'type' => 'int'],
                ['label' => 'Total Tagihan',   'width' => 20, 'type' => 'currency'],
                ['label' => 'Total Terbayar',  'width' => 20, 'type' => 'currency'],
                ['label' => 'Sisa Piutang',    'width' => 20, 'type' => 'currency'],
                ['label' => 'Overdue',         'width' => 20, 'type' => 'currency'],
                ['label' => 'Collection Rate', 'width' => 14, 'align' => 'center'],
                ['label' => 'Draft',           'width' => 9,  'type' => 'int'],
                ['label' => 'Terkirim',        'width' => 10, 'type' => 'int'],
                ['label' => 'Sebagian',        'width' => 10, 'type' => 'int'],
                ['label' => 'Lunas',           'width' => 9,  'type' => 'int'],
            ],
            'rows'    => $dataRows,
            'total'   => [
                '', 'TOTAL', '', '', '', '',
                $this->sumColumn($dataRows, 6),
                $this->sumColumn($dataRows, 7),
                $this->sumColumn($dataRows, 8),
                $this->sumColumn($dataRows, 9),
                $this->sumColumn($dataRows, 10),
                '', '', '', '', '',
            ],
        ];
    }

    // ─── Mutasi Piutang ──────────────────────────────────────────────────

    private function mutasiSections(array $filters, User $user): array
    {
        $scoped = $this->scoped([
            'periode_awal'  => $this->periodeAwal($filters),
            'periode_akhir' => $this->periodeAkhir($filters),
            'klien_ar_id'   => $filters['klien_ar_id'] ?? null,
            'segment'       => $filters['segment'] ?? null,
        ], $user);

        $report  = $this->mutasiPiutangService->getReport($scoped);
        $rows    = $report['rows'] ?? [];
        $periode = 'Periode: ' . ($report['periode_awal'] ?? '-') . ' s/d ' . ($report['periode_akhir'] ?? '-');

        $summaryRows = [];
        $detailRows  = [];

        foreach ($rows as $index => $row) {
            $summaryRows[] = [
                $index + 1,
                $row['kode_klien'] ?? '',
                $row['nama_klien'] ?? '',
                $row['perusahaan'] ?? '',
                $row['pic_ar'] ?? '',
                $row['saldo_awal'] ?? 0,
                $row['invoice_masuk'] ?? 0,
                $row['pembayaran'] ?? 0,
                $row['saldo_akhir'] ?? 0,
            ];

            foreach (($row['details'] ?? []) as $detail) {
                $detailRows[] = [
                    count($detailRows) + 1,
                    $row['kode_klien'] ?? '',
                    $row['nama_klien'] ?? '',
                    $detail['tanggal'] ?? '',
                    $detail['label'] ?? $detail['tipe'] ?? '',
                    $detail['no_dokumen'] ?? '',
                    $detail['no_invoice'] ?? '',
                    $detail['debit'] ?? 0,
                    $detail['kredit'] ?? 0,
                    $detail['saldo'] ?? 0,
                    $detail['keterangan'] ?? '',
                ];
            }
        }

        $summary = $report['summary'] ?? [];

        $summarySection = [
            'title'   => 'MUTASI PIUTANG - RINGKASAN PER KLIEN',
            'meta'    => $periode . '   |   Total Klien: ' . count($summaryRows),
            'theme'   => self::THEMES['mutasi_piutang'],
            'columns' => [
                ['label' => 'No',            'width' => 5,  'type' => 'int', 'align' => 'center'],
                ['label' => 'Kode Klien',    'width' => 16],
                ['label' => 'Nama Klien',    'width' => 30],
                ['label' => 'Entitas',       'width' => 14],
                ['label' => 'PIC AR',        'width' => 20],
                ['label' => 'Saldo Awal',    'width' => 20, 'type' => 'currency'],
                ['label' => 'Invoice Masuk', 'width' => 20, 'type' => 'currency'],
                ['label' => 'Pembayaran',    'width' => 20, 'type' => 'currency'],
                ['label' => 'Saldo Akhir',   'width' => 20, 'type' => 'currency'],
            ],
            'rows'    => $summaryRows,
            'total'   => [
                '', '', '', 'TOTAL', '',
                $summary['saldo_awal'] ?? 0,
                $summary['invoice_masuk'] ?? 0,
                $summary['pembayaran'] ?? 0,
                $summary['saldo_akhir'] ?? 0,
            ],
        ];

        $detailSection = [
            'title'   => 'MUTASI PIUTANG - DETAIL MUTASI',
            'meta'    => $periode . '   |   Total Baris Mutasi: ' . count($detailRows),
            'theme'   => self::THEMES['mutasi_piutang'],
            'columns' => [
                ['label' => 'No',         'width' => 5,  'type' => 'int', 'align' => 'center'],
                ['label' => 'Kode Klien', 'width' => 16],
                ['label' => 'Nama Klien', 'width' => 30],
                ['label' => 'Tanggal',    'width' => 14, 'align' => 'center'],
                ['label' => 'Tipe',       'width' => 14, 'align' => 'center'],
                ['label' => 'Dokumen',    'width' => 24],
                ['label' => 'Invoice',    'width' => 22],
                ['label' => 'Debit',      'width' => 18, 'type' => 'currency'],
                ['label' => 'Kredit',     'width' => 18, 'type' => 'currency'],
                ['label' => 'Saldo',      'width' => 18, 'type' => 'currency'],
                ['label' => 'Keterangan', 'width' => 32],
            ],
            'rows'    => $detailRows,
            'total'   => [
                '', '', '', '', '', '', 'TOTAL',
                $this->sumColumn($detailRows, 7),
                $this->sumColumn($detailRows, 8),
                '', '',
            ],
        ];

        return [$summarySection, $detailSection];
    }

    // ─── Rekening Koran ──────────────────────────────────────────────────

    private function rekeningKoranSection(array $filters): array
    {
        $reportFilters = [
            'periode_awal'     => $this->periodeAwal($filters),
            'periode_akhir'    => $this->periodeAkhir($filters),
            'pic_ar_id'        => $filters['pic_ar_id'] ?? null,
            'bank_type'        => $filters['bank_type'] ?? null,
            'dk'               => $filters['dk'] ?? null,
            'status_posting_1' => $filters['status_posting_1'] ?? null,
            'status_posting_2' => $filters['status_posting_2'] ?? null,
        ];

        $rows    = $this->rekeningKoranService->getAllRows($reportFilters);
        $summary = $this->rekeningKoranService->getSummary($reportFilters);

        $dataRows = [];
        foreach ($rows as $index => $row) {
            $dataRows[] = [
                $index + 1,
                $row['no_referensi'] ?? '',
                $row['tanggal'] ?? '',
                $row['waktu_transaksi'] ?? '',
                $row['bank_type'] ?? '',
                $row['dk'] ?? '',
                $row['mutasi'] ?? 0,
                $row['saldo'] ?? 0,
                $row['keterangan'] ?? '',
                $row['status_posting_1'] ?? '',
                $row['no_dokumen_ar'] ?? '',
                $row['selisih'] ?? 0,
                $row['status_posting_2'] ?? '',
                $row['pic_ar'] ?? '',
                $row['posted_by'] ?? '',
                $row['posted_at'] ?? '',
            ];
        }

        return [
            'title'   => 'REKENING KORAN - JURNAL UMUM BANK',
            'meta'    => 'Periode: ' . ($reportFilters['periode_awal'] ?? '-') . ' s/d ' . ($reportFilters['periode_akhir'] ?? '-')
                . '   |   Transaksi: ' . ($summary['total_transaksi'] ?? 0)
                . '   |   Matched: ' . ($summary['total_matched'] ?? 0)
                . '   |   Posted: ' . ($summary['total_posted'] ?? 0)
                . '   |   Pending: ' . ($summary['total_pending'] ?? 0)
                . '   |   Mutasi Masuk: Rp ' . number_format((float) ($summary['total_mutasi_masuk'] ?? 0), 0, ',', '.'),
            'theme'   => self::THEMES['rekening_koran'],
            'columns' => [
                ['label' => 'No',               'width' => 5,  'type' => 'int', 'align' => 'center'],
                ['label' => 'TRXID',            'width' => 22],
                ['label' => 'Tanggal',          'width' => 13, 'align' => 'center'],
                ['label' => 'Waktu',            'width' => 10, 'align' => 'center'],
                ['label' => 'Bank',             'width' => 12, 'align' => 'center'],
                ['label' => 'D/K',              'width' => 7,  'align' => 'center'],
                ['label' => 'Mutasi',           'width' => 20, 'type' => 'currency'],
                ['label' => 'Saldo',            'width' => 20, 'type' => 'currency'],
                ['label' => 'Deskripsi',        'width' => 36],
                ['label' => 'Status Posting 1', 'width' => 16, 'align' => 'center'],
                ['label' => 'No Dokumen AR',    'width' => 22],
                ['label' => 'Selisih',          'width' => 18, 'type' => 'currency'],
                ['label' => 'Status Posting 2', 'width' => 16, 'align' => 'center'],
                ['label' => 'PIC AR',           'width' => 20],
                ['label' => 'Posted Oleh',      'width' => 20],
                ['label' => 'Posted Pada',      'width' => 18, 'align' => 'center'],
            ],
            'rows'    => $dataRows,

            // Mutasi mencampur debit & kredit, jadi total kolomnya tidak bermakna —
            // angka ringkasannya sudah ada di baris meta.
            'total'   => null,
        ];
    }

    // ─── Riwayat Pembayaran ──────────────────────────────────────────────

    private function riwayatPembayaranSection(array $filters, User $user): array
    {
        $reportFilters = [
            'tanggal_dari'      => $filters['tanggal_dari'] ?? null,
            'tanggal_sampai'    => $filters['tanggal_sampai'] ?? null,
            'klien_ar_id'       => $filters['klien_ar_id'] ?? null,
            'metode_pembayaran' => $filters['metode_pembayaran'] ?? null,
            'segment'           => $filters['segment'] ?? null,
        ];

        $rows = $this->riwayatPembayaranService->getAll($reportFilters, $user);

        $dataRows = [];
        foreach ($rows as $index => $pembayaran) {
            $bankDetail = $pembayaran->bankStatementDetail ?: $pembayaran->sumberPembayaran?->bankStatementDetail;

            // Multi Payment: invoice_id header NULL, alokasinya di items — tanpa
            // fallback ini baris Multi Payment (baru ikut tampil sejak
            // RiwayatPembayaranService men-scope lewat items juga) akan tampil
            // kosong di kolom No Invoice/Klien/PIC AR/Entitas.
            $isMultiPayment = $pembayaran->invoice_id === null && $pembayaran->items->isNotEmpty();
            $primaryInvoice = $pembayaran->invoice ?? $pembayaran->items->first()?->invoice;
            $noInvoiceLabel = $isMultiPayment
                ? sprintf('%d Invoice (Multi Payment)', $pembayaran->items->count())
                : ($primaryInvoice?->no_invoice ?? '');

            $dataRows[] = [
                $index + 1,
                $pembayaran->tanggal_pembayaran?->toDateString() ?? '',
                $noInvoiceLabel,
                $primaryInvoice?->klienAr?->nama_klien ?? '',
                (float) $pembayaran->jumlah_pembayaran,
                $pembayaran->metode_pembayaran ?? '',
                $bankDetail?->status_cocok ?? 'MANUAL',
                $pembayaran->no_referensi ?? '',
                $this->jenisPembayaran($pembayaran),
                $primaryInvoice?->karyawan?->nama_karyawan ?? '',
                $primaryInvoice?->perusahaan?->nama_singkatan_perusahaan ?? '',
                $pembayaran->createdBy?->karyawan?->nama_karyawan ?? $pembayaran->createdBy?->username ?? '',
            ];
        }

        return [
            'title'   => 'RIWAYAT PEMBAYARAN AR',
            'meta'    => 'Periode: ' . ($reportFilters['tanggal_dari'] ?? '-') . ' s/d ' . ($reportFilters['tanggal_sampai'] ?? '-')
                . '   |   Segment: ' . $this->segmentLabel($filters)
                . '   |   Total Transaksi: ' . count($dataRows),
            'theme'   => self::THEMES['riwayat_pembayaran'],
            'columns' => [
                ['label' => 'No',           'width' => 5,  'type' => 'int', 'align' => 'center'],
                ['label' => 'Tanggal',      'width' => 13, 'align' => 'center'],
                ['label' => 'No Invoice',   'width' => 22],
                ['label' => 'Klien',        'width' => 30],
                ['label' => 'Jumlah',       'width' => 20, 'type' => 'currency'],
                ['label' => 'Metode',       'width' => 12, 'align' => 'center'],
                ['label' => 'Status Rekon', 'width' => 14, 'align' => 'center'],
                ['label' => 'No Referensi', 'width' => 24],
                ['label' => 'Jenis',        'width' => 10, 'align' => 'center'],
                ['label' => 'PIC AR',       'width' => 20],
                ['label' => 'Entitas',      'width' => 14],
                ['label' => 'Dicatat Oleh', 'width' => 20],
            ],
            'rows'    => $dataRows,
            'total'   => [
                '', '', '', 'TOTAL',
                $this->sumColumn($dataRows, 4),
                '', '', '', '', '', '', '',
            ],
        ];
    }

    // ─── Rekap Pembayaran ────────────────────────────────────────────────

    private function rekapPembayaranSection(array $filters, User $user): array
    {
        $scoped = $this->scoped([
            'tanggal_dari'      => $filters['tanggal_dari'] ?? null,
            'tanggal_sampai'    => $filters['tanggal_sampai'] ?? null,
            'klien_ar_id'       => $filters['klien_ar_id'] ?? null,
            'metode_pembayaran' => $filters['metode_pembayaran'] ?? null,
            'segment'           => $filters['segment'] ?? null,
        ], $user);

        $report  = $this->rekapPembayaranService->getReport($scoped);
        $summary = $report['summary'] ?? [];

        $dataRows = [];
        foreach (($report['rows'] ?? []) as $index => $row) {
            $dataRows[] = [
                $index + 1,
                $this->asDateString($row['tanggal'] ?? null),
                $row['client'] ?? '',
                $row['invoice'] ?? '',
                $row['ref_payment'] ?? '',
                $row['metode'] ?? '',
                $row['nominal'] ?? 0,
                $row['pic_ar'] ?? '',
                !empty($row['is_rekon']) ? 'Ya' : 'Tidak',
            ];
        }

        return [
            'title'   => 'REKAP PEMBAYARAN AR',
            'meta'    => 'Periode: ' . ($report['tanggal_dari'] ?? '-') . ' s/d ' . ($report['tanggal_sampai'] ?? '-')
                . '   |   Transfer: Rp ' . number_format((float) ($summary['transfer'] ?? 0), 0, ',', '.')
                . '   |   Cash: Rp ' . number_format((float) ($summary['cash'] ?? 0), 0, ',', '.')
                . '   |   Giro: Rp ' . number_format((float) ($summary['giro'] ?? 0), 0, ',', '.')
                . '   |   Total Transaksi: ' . count($dataRows),
            'theme'   => self::THEMES['rekap_pembayaran'],
            'columns' => [
                ['label' => 'No',          'width' => 5,  'type' => 'int', 'align' => 'center'],
                ['label' => 'Tanggal',     'width' => 13, 'align' => 'center'],
                ['label' => 'Client',      'width' => 30],
                ['label' => 'Invoice',     'width' => 22],
                ['label' => 'Ref Payment', 'width' => 24],
                ['label' => 'Metode',      'width' => 12, 'align' => 'center'],
                ['label' => 'Nominal',     'width' => 20, 'type' => 'currency'],
                ['label' => 'PIC AR',      'width' => 22],
                ['label' => 'Rekon',       'width' => 10, 'align' => 'center'],
            ],
            'rows'    => $dataRows,
            'total'   => [
                '', '', '', '', '', 'TOTAL',
                $summary['total'] ?? $this->sumColumn($dataRows, 6),
                '', '',
            ],
        ];
    }

    // ─── Pendapatan di Muka ──────────────────────────────────────────────

    private function pendapatanDiMukaSection(array $filters): array
    {
        $reportFilters = [
            'tanggal_dari'   => $filters['tanggal_dari'] ?? null,
            'tanggal_sampai' => $filters['tanggal_sampai'] ?? null,
            'klien_ar_id'    => $filters['klien_ar_id'] ?? null,
            'status'         => $filters['status'] ?? null,
        ];

        $rows = $this->pendapatanDiMukaService->getAllFormatted($reportFilters);

        $dataRows = [];
        foreach ($rows as $index => $row) {
            $dataRows[] = [
                $index + 1,
                $row['tanggal_pencatatan'] ?? '',
                $row['klien'] ?? '',
                $row['investor'] ?? '',
                $row['no_referensi_sumber'] ?? '',
                $row['jumlah'] ?? 0,
                $row['sisa_pdm'] ?? 0,
                $row['status'] ?? '',
                $row['keterangan'] ?? '',
                $row['created_by'] ?? '',
            ];
        }

        return [
            'title'   => 'PENDAPATAN DITERIMA DI MUKA',
            'meta'    => 'Periode: ' . ($reportFilters['tanggal_dari'] ?? '-') . ' s/d ' . ($reportFilters['tanggal_sampai'] ?? '-')
                . '   |   Jumlah Record: ' . count($dataRows),
            'theme'   => self::THEMES['pendapatan_di_muka'],
            'columns' => [
                ['label' => 'No',                 'width' => 5,  'type' => 'int', 'align' => 'center'],
                ['label' => 'Tanggal Pencatatan', 'width' => 16, 'align' => 'center'],
                ['label' => 'Klien',              'width' => 30],
                ['label' => 'Investor',           'width' => 28],
                ['label' => 'No Ref Sumber',      'width' => 24],
                ['label' => 'Jumlah PDM',         'width' => 20, 'type' => 'currency'],
                ['label' => 'Sisa PDM',           'width' => 20, 'type' => 'currency'],
                ['label' => 'Status',             'width' => 14, 'align' => 'center'],
                ['label' => 'Keterangan',         'width' => 32],
                ['label' => 'Dicatat Oleh',       'width' => 20],
            ],
            'rows'    => $dataRows,
            'total'   => [
                '', '', '', '', 'TOTAL',
                $this->sumColumn($dataRows, 5),
                $this->sumColumn($dataRows, 6),
                '', '', '',
            ],
        ];
    }

    // ─── Jurnal Aktivitas per PIC ────────────────────────────────────────

    private function jurnalPicSection(array $filters, User $user): array
    {
        $scoped = $this->scoped([
            'no_referensi'        => $filters['no_referensi'] ?? null,
            'karyawan_id'         => $filters['karyawan_id'] ?? null,
            'klien_ar_id'         => $filters['klien_ar_id'] ?? null,
            'metode_pembayaran'   => $filters['metode_pembayaran'] ?? null,
            'tanggal_dari'        => $filters['tanggal_dari'] ?? null,
            'tanggal_sampai'      => $filters['tanggal_sampai'] ?? null,
            'status_rekonsiliasi' => $filters['status_rekonsiliasi'] ?? null,
        ], $user);

        $rows    = $this->jurnalPicService->getJurnalAll($scoped);
        $summary = $this->jurnalPicService->getSummary($scoped);

        $dataRows = [];
        foreach ($rows as $index => $pembayaran) {
            $bankDetail = $pembayaran->bankStatementDetail ?: $pembayaran->sumberPembayaran?->bankStatementDetail;

            $dataRows[] = [
                $index + 1,
                $pembayaran->no_referensi ?? '',
                $pembayaran->tanggal_pembayaran?->toDateString() ?? '',
                $pembayaran->invoice?->no_invoice ?? '',
                $pembayaran->invoice?->klienAr?->nama_klien ?? '',
                $pembayaran->invoice?->karyawan?->nama_karyawan ?? '',
                $pembayaran->invoice?->perusahaan?->nama_singkatan_perusahaan ?? '',
                $pembayaran->metode_pembayaran ?? '',
                (float) $pembayaran->jumlah_pembayaran,
                $bankDetail?->status_cocok ?? 'Belum Diproses',
            ];
        }

        return [
            'title'   => 'JURNAL AKTIVITAS PER PIC AR',
            'meta'    => 'Periode: ' . ($filters['tanggal_dari'] ?? '-') . ' s/d ' . ($filters['tanggal_sampai'] ?? '-')
                . '   |   Total Transaksi: ' . ($summary['total_transaksi'] ?? count($dataRows))
                . '   |   Matched: ' . ($summary['total_matched'] ?? 0)
                . '   |   Belum Direkonsiliasi: ' . ($summary['total_belum_cocok'] ?? 0),
            'theme'   => self::THEMES['jurnal_pic'],
            'columns' => [
                ['label' => 'No',                  'width' => 5,  'type' => 'int', 'align' => 'center'],
                ['label' => 'No Referensi',        'width' => 24],
                ['label' => 'Tanggal Bayar',       'width' => 14, 'align' => 'center'],
                ['label' => 'No Invoice',          'width' => 22],
                ['label' => 'Klien',               'width' => 28],
                ['label' => 'PIC AR',              'width' => 22],
                ['label' => 'Entitas',             'width' => 16],
                ['label' => 'Metode',              'width' => 12, 'align' => 'center'],
                ['label' => 'Nominal',             'width' => 20, 'type' => 'currency'],
                ['label' => 'Status Rekonsiliasi', 'width' => 20, 'align' => 'center'],
            ],
            'rows'    => $dataRows,
            'total'   => [
                '', '', '', '', '', '', '', 'TOTAL',
                $summary['total_nominal'] ?? $this->sumColumn($dataRows, 8),
                '',
            ],
        ];
    }

    // ─── Kinerja AR ──────────────────────────────────────────────────────

    private function kinerjaArSection(array $filters): array
    {
        $report = $this->kinerjaArService->getReport([
            'periode_awal'  => $this->periodeAwal($filters),
            'periode_akhir' => $this->periodeAkhir($filters),
            'segment'       => $filters['segment'] ?? null,
        ]);

        $summary = $report['summary'] ?? [];

        $dataRows = [];
        foreach (($report['rows'] ?? []) as $index => $row) {
            $dataRows[] = [
                $index + 1,
                $row['nama_karyawan'] ?? '',
                $row['perusahaan'] ?? '',
                $row['jumlah_klien'] ?? 0,
                $row['jumlah_invoice'] ?? 0,
                $row['total_tagihan'] ?? 0,
                $row['total_terkumpul'] ?? 0,
                $row['total_sisa'] ?? 0,
                ($row['collection_rate'] ?? 0) . '%',
            ];
        }

        return [
            'title'   => 'KINERJA AR PER PIC',
            'meta'    => 'Periode: ' . ($report['periode_awal'] ?? '-') . ' s/d ' . ($report['periode_akhir'] ?? '-')
                . '   |   Jumlah AR Officer: ' . count($dataRows),
            'theme'   => self::THEMES['kinerja_ar'],
            'columns' => [
                ['label' => 'No',              'width' => 5,  'type' => 'int', 'align' => 'center'],
                ['label' => 'AR Officer',      'width' => 28],
                ['label' => 'Entitas',         'width' => 14],
                ['label' => 'Jml Klien',       'width' => 12, 'type' => 'int'],
                ['label' => 'Jml Invoice',     'width' => 14, 'type' => 'int'],
                ['label' => 'Total Tagihan',   'width' => 22, 'type' => 'currency'],
                ['label' => 'Terkumpul',       'width' => 22, 'type' => 'currency'],
                ['label' => 'Sisa',            'width' => 22, 'type' => 'currency'],
                ['label' => 'Collection Rate', 'width' => 16, 'align' => 'center'],
            ],
            'rows'    => $dataRows,
            'total'   => [
                '', 'TOTAL', '',
                $this->sumColumn($dataRows, 3),
                $this->sumColumn($dataRows, 4),
                $summary['total_tagihan'] ?? 0,
                $summary['total_terkumpul'] ?? 0,
                $summary['total_sisa'] ?? 0,
                ($summary['collection_rate'] ?? 0) . '%',
            ],
        ];
    }

    // ─── Helper ──────────────────────────────────────────────────────────

    /**
     * Buang filter kosong lalu terapkan role scoping yang sama dengan halaman
     * laporan satuan (PIC AR hanya boleh melihat kliennya sendiri).
     */
    private function scoped(array $filters, User $user): array
    {
        $filters = array_filter($filters, fn($value) => $value !== null && $value !== '');
        ArFilterScope::apply($filters, $user);

        return $filters;
    }

    private function periodeAwal(array $filters): ?string
    {
        return $filters['periode_awal'] ?? $filters['tanggal_dari'] ?? null;
    }

    private function periodeAkhir(array $filters): ?string
    {
        return $filters['periode_akhir'] ?? $filters['tanggal_sampai'] ?? null;
    }

    private function segmentLabel(array $filters): string
    {
        $segment = strtoupper((string) ($filters['segment'] ?? ''));

        return in_array($segment, ['B2B', 'B2C'], true) ? $segment : 'Semua';
    }

    private function periodeBulanLabel(array $filters): string
    {
        $bulan = $filters['periode_bulan'] ?? null;
        $tahun = $filters['periode_tahun'] ?? null;

        $namaBulan = $bulan
            ? ([
                1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
            ][(int) $bulan] ?? '')
            : 'Semua Bulan';

        return trim($namaBulan . ' ' . ($tahun ?? ''));
    }

    private function jenisPembayaran(PembayaranAr $pembayaran): string
    {
        $ref = $pembayaran->no_referensi ?? '';

        return match (true) {
            str_contains($ref, '/PDM-') => 'PDM',
            str_contains($ref, '/ALO-') => 'ALO',
            default                     => 'REGULER',
        };
    }

    private function asDateString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
    }

    /** @param  array<int, array>  $rows */
    private function sumColumn(array $rows, int $index): float
    {
        return array_sum(array_map(fn(array $row) => (float) ($row[$index] ?? 0), $rows));
    }
}
