<?php

namespace App\Domain\Finance\OpeningBalance\Services;

use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Models\Barang;
use App\Models\Invoice;
use App\Models\KlienAr;
use App\Models\OpeningBalanceImportBatch;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as PhpSpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Memproses import Opening Balance AR dari file CSV/XLSX yang diupload lewat tab
 * "Import Master Opening Balance" (halaman Import Master Data).
 *
 * Kedua format sama-sama mendukung Rincian Invoice Asal & Item Invoice Asal secara
 * OPSIONAL (boleh dikosongkan sepenuhnya untuk OB yang hanya berupa saldo agregat):
 *   - XLSX: 3 sheet terpisah (Data Opening Balance + Rincian Invoice Asal + Item
 *     Invoice Asal), kapasitas realistis lebih kecil karena styling & 3 sheet data.
 *   - CSV: 1 tabel flat dengan kolom penanda `tipe_baris` (OB/RINCIAN/ITEM) — setiap
 *     baris "berperan" sebagai salah satu dari 3 entitas, memakai subset kolom header
 *     gabungan yang relevan (lihat OpeningBalanceImportTemplateService). Mendukung
 *     volume jauh lebih besar dari XLSX, cocok untuk backfill data historis (mis. s/d
 *     3 tahun ke belakang), baik dengan maupun tanpa rincian.
 *
 * Kedua jalur parsing menghasilkan struktur data identik ($obRows + $detailsByOb
 * bersarang) lewat linkDetailsAndItems() bersama, supaya logic linking/orphan-
 * detection tidak dobel dan hasil akhirnya konsisten apa pun format filenya.
 *
 * Partial-write (BEDA dari desain lama yang all-or-nothing): setiap baris OB
 * memanggil InvoiceService::createOpeningBalance() satu per satu — method itu
 * membungkus transaksinya sendiri, jadi baris yang gagal dicatat sebagai error dan
 * dilewati, baris lain tetap diproses.
 */
class OpeningBalanceImportService
{
    private const MAX_STORED_ERRORS = 200;

    private const CSV_TIPE_OB = 'OB';

    private const CSV_TIPE_RINCIAN = 'RINCIAN';

    private const CSV_TIPE_ITEM = 'ITEM';

    public function __construct(private readonly InvoiceService $invoiceService) {}

    public function process(OpeningBalanceImportBatch $batch): void
    {
        $disk = Storage::disk('local');
        if (! $batch->file_path || ! $disk->exists($batch->file_path)) {
            throw new \RuntimeException("File import tidak ditemukan: {$batch->file_path}");
        }

        $batch->update(['status' => 'processing']);

        $filePath = $disk->path($batch->file_path);
        $ext = strtolower(pathinfo($batch->original_filename ?: $batch->file_path, PATHINFO_EXTENSION));
        $isCsv = ! in_array($ext, ['xlsx', 'xls'], true);

        [$obRows, $detailsByOb, $errors] = $isCsv ? $this->parseCsv($filePath) : $this->parseXlsx($filePath);

        $batch->update(['total_ob' => count($obRows)]);

        $klienByKode = KlienAr::whereNotNull('kode_klien')
            ->get(['id', 'kode_klien', 'nama_klien', 'perusahaan_id'])
            ->keyBy(fn (KlienAr $k) => strtolower($k->kode_klien));

        $namaGroups = KlienAr::all(['id', 'kode_klien', 'nama_klien', 'perusahaan_id'])
            ->groupBy(fn (KlienAr $k) => strtolower(trim($k->nama_klien)));

        $barangByKode = Barang::whereNotNull('kode_barang')->get(['id', 'kode_barang'])
            ->keyBy(fn (Barang $b) => strtolower($b->kode_barang));
        $barangByNama = Barang::all(['id', 'nama_barang'])
            ->keyBy(fn (Barang $b) => strtolower(trim($b->nama_barang)));

        $insertedOb = $skippedOb = $failedOb = 0;
        $totalDetail = $insertedDetail = 0;
        $totalItem = $insertedItem = 0;
        $processed = 0;

        foreach ($obRows as $noUrut => $row) {
            $processed++;
            if ($processed % 50 === 0) {
                $batch->update([
                    'processed_ob' => $processed,
                    'inserted_ob' => $insertedOb,
                    'skipped_ob' => $skippedOb,
                    'failed_ob' => $failedOb,
                    'inserted_detail' => $insertedDetail,
                    'inserted_item' => $insertedItem,
                ]);
            }

            $resolved = $this->resolveKlien($row['kode_klien'], $row['nama_klien'], $klienByKode, $namaGroups);
            if (! $resolved['klien']) {
                $errors[] = ['sheet' => $row['sheet'], 'row' => $row['source_row'], 'message' => $resolved['error']];
                $failedOb++;

                continue;
            }
            $klien = $resolved['klien'];

            if (! $row['tanggal']) {
                $errors[] = ['sheet' => $row['sheet'], 'row' => $row['source_row'], 'message' => 'Tanggal Opening Balance tidak valid.'];
                $failedOb++;

                continue;
            }

            $rowDetails = $detailsByOb[$noUrut] ?? [];

            if ($row['saldo_awal'] <= 0 && empty($rowDetails)) {
                $errors[] = ['sheet' => $row['sheet'], 'row' => $row['source_row'], 'message' => 'saldo_awal harus lebih dari 0.'];
                $failedOb++;

                continue;
            }

            $exists = Invoice::where('klien_ar_id', $klien->id)
                ->where('is_opening_balance', true)
                ->whereDate('tanggal_invoice', $row['tanggal'])
                ->exists();
            if ($exists) {
                $skippedOb++;

                continue;
            }

            $details = [];
            $sisaSum = 0.0;

            foreach ($rowDetails as $d) {
                $items = [];
                foreach ($d['items'] as $it) {
                    $barangId = $this->resolveBarangId($it['kode_barang'], $it['nama_barang'], $barangByKode, $barangByNama);
                    $subtotal = $it['subtotal'] > 0 ? $it['subtotal'] : round($it['qty'] * $it['harga_satuan'], 2);
                    $items[] = [
                        'barang_id' => $barangId,
                        'kode_barang' => $it['kode_barang'],
                        'nama_barang' => $it['nama_barang'],
                        'qty' => $it['qty'],
                        'satuan' => $it['satuan'],
                        'harga_satuan' => $it['harga_satuan'],
                        'subtotal' => $subtotal,
                        'keterangan' => $it['keterangan'],
                    ];
                    $totalItem++;
                }

                $details[] = [
                    'no_invoice_asal' => $d['no_invoice_asal'],
                    'tanggal_invoice_asal' => $d['tanggal_invoice_asal'],
                    'deskripsi' => $d['deskripsi'],
                    'jumlah_tagihan_asal' => $d['jumlah_tagihan_asal'],
                    'sisa_tagihan_asal' => $d['sisa_tagihan_asal'],
                    'keterangan' => $d['keterangan'],
                    'items' => $items,
                ];
                $sisaSum += $d['sisa_tagihan_asal'];
                $totalDetail++;
            }

            if (! empty($details) && abs($sisaSum - $row['saldo_awal']) > 0.01) {
                $errors[] = [
                    'sheet' => 'Rincian Invoice Asal',
                    'row' => $row['source_row'],
                    'message' => sprintf(
                        'Total sisa_tagihan_asal (%s) tidak sama dengan saldo_awal (%s) untuk no_urut %s.',
                        number_format($sisaSum, 2), number_format($row['saldo_awal'], 2), $noUrut,
                    ),
                ];
                $failedOb++;

                continue;
            }

            $saldoAwal = ! empty($details) ? $sisaSum : $row['saldo_awal'];

            $data = [
                'no_invoice' => $this->invoiceService->generateOpeningBalanceNoInvoice($klien, $row['tanggal']),
                'tanggal' => $row['tanggal'],
                'klien_ar_id' => $klien->id,
                'saldo_awal' => $saldoAwal,
                'keterangan' => $row['keterangan'] ?: 'Opening Balance (Import)',
                'details' => $details,
            ];

            try {
                $this->invoiceService->createOpeningBalance($data, notify: false);
                $insertedOb++;
                $insertedDetail += count($details);
                $insertedItem += array_sum(array_map(fn ($d) => count($d['items']), $details));
            } catch (Throwable $e) {
                $errors[] = ['sheet' => $row['sheet'], 'row' => $row['source_row'], 'message' => 'Gagal menyimpan: '.$e->getMessage()];
                $failedOb++;
            }
        }

        if (count($errors) > self::MAX_STORED_ERRORS) {
            $errors = array_slice($errors, 0, self::MAX_STORED_ERRORS);
        }

        $batch->update([
            'processed_ob' => count($obRows),
            'inserted_ob' => $insertedOb,
            'skipped_ob' => $skippedOb,
            'failed_ob' => $failedOb,
            'total_detail' => $totalDetail,
            'inserted_detail' => $insertedDetail,
            'total_item' => $totalItem,
            'inserted_item' => $insertedItem,
            'errors' => $errors,
            'status' => 'completed',
            'message' => sprintf(
                'Opening Balance +%d ⊘%d ✗%d | Rincian Invoice Asal +%d | Item +%d',
                $insertedOb, $skippedOb, $failedOb, $insertedDetail, $insertedItem,
            ),
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Resolusi Client AR & Barang
    // ──────────────────────────────────────────────────────────────

    /**
     * kode_klien (kalau diisi) selalu jadi kunci utama — paling stabil karena unique
     * di DB. Fallback nama_klien: case-insensitive, dan DITOLAK eksplisit kalau
     * cocok dengan lebih dari 1 klien (beda dari desain lama yang diam-diam memilih
     * klien "terbaru" — silent wrong-match risk).
     *
     * Mengembalikan hasil lewat return (bukan by-ref $errors) supaya method ini tetap
     * pure/mudah diuji — pola sama seperti resolvePicRestoForRow() di MasterImportService.
     *
     * @return array{klien: ?KlienAr, error: ?string}
     */
    private function resolveKlien(?string $kodeKlien, string $namaKlien, $klienByKode, $namaGroups): array
    {
        if ($kodeKlien) {
            $klien = $klienByKode->get(strtolower($kodeKlien));
            if (! $klien) {
                return ['klien' => null, 'error' => "Client dengan kode_klien '{$kodeKlien}' tidak ditemukan."];
            }

            return ['klien' => $klien, 'error' => null];
        }

        $matches = $namaGroups->get(strtolower(trim($namaKlien))) ?? collect();

        if ($matches->isEmpty()) {
            return ['klien' => null, 'error' => "Client '{$namaKlien}' tidak ditemukan."];
        }

        if ($matches->count() > 1) {
            return [
                'klien' => null,
                'error' => "Nama klien '{$namaKlien}' cocok dengan {$matches->count()} klien berbeda — isi kolom kode_klien untuk memastikan.",
            ];
        }

        return ['klien' => $matches->first(), 'error' => null];
    }

    private function resolveBarangId(?string $kodeBarang, string $namaBarang, $barangByKode, $barangByNama): ?int
    {
        if ($kodeBarang) {
            $barang = $barangByKode->get(strtolower($kodeBarang));
            if ($barang) {
                return $barang->id;
            }
        }

        return $barangByNama->get(strtolower(trim($namaBarang)))?->id;
    }

    // ──────────────────────────────────────────────────────────────
    //  CSV — 1 tabel flat, dibedakan kolom `tipe_baris` (OB/RINCIAN/ITEM)
    // ──────────────────────────────────────────────────────────────

    /**
     * Urutan kolom HARUS identik dengan header gabungan di
     * OpeningBalanceImportTemplateService::buildRows().
     */
    private const CSV_COL_TIPE_BARIS = 0;

    private const CSV_COL_NO_URUT = 1;

    private const CSV_COL_KODE_KLIEN = 2;

    private const CSV_COL_NAMA_KLIEN = 3;

    private const CSV_COL_TANGGAL = 4;

    private const CSV_COL_SALDO_AWAL = 5;

    private const CSV_COL_NO_INVOICE_ASAL = 6;

    private const CSV_COL_TANGGAL_INVOICE_ASAL = 7;

    private const CSV_COL_DESKRIPSI = 8;

    private const CSV_COL_JUMLAH_TAGIHAN_ASAL = 9;

    private const CSV_COL_SISA_TAGIHAN_ASAL = 10;

    private const CSV_COL_KODE_BARANG = 11;

    private const CSV_COL_NAMA_BARANG = 12;

    private const CSV_COL_QTY = 13;

    private const CSV_COL_SATUAN = 14;

    private const CSV_COL_HARGA_SATUAN = 15;

    private const CSV_COL_SUBTOTAL = 16;

    private const CSV_COL_KETERANGAN = 17;

    /** @return array{0: array<int|string, array>, 1: array<int|string, array>, 2: array} [obRows keyed by no_urut, detailsByOb keyed by no_urut, errors] */
    private function parseCsv(string $filePath): array
    {
        $errors = [];
        $obRows = [];
        $rawDetails = [];
        $rawItems = [];

        $delimiter = $this->detectCsvDelimiter($filePath);
        $handle = $this->openCsvHandle($filePath);

        $headerFound = false;
        $lineNumber = 0;

        while (($row = fgetcsv($handle, null, $delimiter)) !== false) {
            $lineNumber++;

            if (! $headerFound) {
                if ($this->normalizeHeaderName((string) ($row[self::CSV_COL_TIPE_BARIS] ?? '')) === 'tipe_baris') {
                    $headerFound = true;
                }

                continue;
            }

            $tipeRaw = trim((string) ($row[self::CSV_COL_TIPE_BARIS] ?? ''));
            if ($tipeRaw === '' || str_starts_with($tipeRaw, '#')) {
                continue; // baris kosong atau baris komentar penuh
            }

            $tipe = strtoupper($tipeRaw);
            $noUrut = trim((string) ($row[self::CSV_COL_NO_URUT] ?? ''));
            $keterangan = $this->importValue($row[self::CSV_COL_KETERANGAN] ?? null);

            if ($tipe === self::CSV_TIPE_OB) {
                $kodeKlienRaw = trim((string) ($row[self::CSV_COL_KODE_KLIEN] ?? ''));
                if (str_starts_with($kodeKlienRaw, '[CONTOH]')) {
                    continue;
                }

                $namaKlien = trim((string) ($row[self::CSV_COL_NAMA_KLIEN] ?? ''));

                if ($noUrut === '') {
                    $errors[] = ['sheet' => 'Data Opening Balance', 'row' => $lineNumber, 'message' => 'no_urut wajib diisi untuk baris tipe_baris=OB.'];

                    continue;
                }
                if ($namaKlien === '') {
                    $errors[] = ['sheet' => 'Data Opening Balance', 'row' => $lineNumber, 'message' => 'nama_klien wajib diisi untuk baris tipe_baris=OB.'];

                    continue;
                }
                if (isset($obRows[$noUrut])) {
                    $errors[] = ['sheet' => 'Data Opening Balance', 'row' => $lineNumber, 'message' => "no_urut '{$noUrut}' duplikat (sudah dipakai baris OB lain)."];

                    continue;
                }

                $obRows[$noUrut] = [
                    'sheet' => 'Data Opening Balance',
                    'source_row' => $lineNumber,
                    'kode_klien' => $this->importValue($kodeKlienRaw),
                    'nama_klien' => $namaKlien,
                    'tanggal' => $this->importDate($row[self::CSV_COL_TANGGAL] ?? null),
                    'saldo_awal' => $this->importNum($row[self::CSV_COL_SALDO_AWAL] ?? null),
                    'keterangan' => $keterangan,
                ];

                continue;
            }

            if ($tipe === self::CSV_TIPE_RINCIAN) {
                $deskripsi = trim((string) ($row[self::CSV_COL_DESKRIPSI] ?? ''));
                if (str_starts_with($deskripsi, '[CONTOH]')) {
                    continue;
                }

                $noInvoiceAsal = trim((string) ($row[self::CSV_COL_NO_INVOICE_ASAL] ?? ''));

                if ($noUrut === '' || $noInvoiceAsal === '') {
                    $errors[] = ['sheet' => 'Rincian Invoice Asal', 'row' => $lineNumber, 'message' => 'no_urut dan no_invoice_asal wajib diisi untuk baris tipe_baris=RINCIAN.'];

                    continue;
                }

                $tanggalAsal = $this->importDate($row[self::CSV_COL_TANGGAL_INVOICE_ASAL] ?? null);
                $sisaTagihan = $this->importNum($row[self::CSV_COL_SISA_TAGIHAN_ASAL] ?? null);

                if (! $tanggalAsal || $deskripsi === '' || $sisaTagihan <= 0) {
                    $errors[] = ['sheet' => 'Rincian Invoice Asal', 'row' => $lineNumber, 'message' => 'tanggal_invoice_asal, deskripsi wajib diisi, dan sisa_tagihan_asal harus lebih dari 0 untuk baris tipe_baris=RINCIAN.'];

                    continue;
                }

                $rawDetails[$noUrut][] = [
                    'source_row' => $lineNumber,
                    'no_invoice_asal' => $noInvoiceAsal,
                    'tanggal_invoice_asal' => $tanggalAsal,
                    'deskripsi' => $deskripsi,
                    'jumlah_tagihan_asal' => $this->importNum($row[self::CSV_COL_JUMLAH_TAGIHAN_ASAL] ?? null),
                    'sisa_tagihan_asal' => $sisaTagihan,
                    'keterangan' => $keterangan,
                ];

                continue;
            }

            if ($tipe === self::CSV_TIPE_ITEM) {
                $namaBarang = trim((string) ($row[self::CSV_COL_NAMA_BARANG] ?? ''));
                if (str_starts_with($namaBarang, '[CONTOH]')) {
                    continue;
                }

                $noInvoiceAsal = trim((string) ($row[self::CSV_COL_NO_INVOICE_ASAL] ?? ''));
                $qty = $this->importNum($row[self::CSV_COL_QTY] ?? null);
                $hargaSatuan = $this->importNum($row[self::CSV_COL_HARGA_SATUAN] ?? null);

                if ($noUrut === '' || $noInvoiceAsal === '' || $namaBarang === '' || $qty <= 0) {
                    $errors[] = ['sheet' => 'Item Invoice Asal', 'row' => $lineNumber, 'message' => 'no_urut, no_invoice_asal, nama_barang wajib diisi, dan qty harus lebih dari 0 untuk baris tipe_baris=ITEM.'];

                    continue;
                }

                $key = $noUrut.'|'.strtolower(trim($noInvoiceAsal));
                $rawItems[$key][] = [
                    'source_row' => $lineNumber,
                    'kode_barang' => $this->importValue($row[self::CSV_COL_KODE_BARANG] ?? null),
                    'nama_barang' => $namaBarang,
                    'qty' => $qty,
                    'satuan' => $this->importValue($row[self::CSV_COL_SATUAN] ?? null),
                    'harga_satuan' => $hargaSatuan,
                    'subtotal' => $this->importNum($row[self::CSV_COL_SUBTOTAL] ?? null),
                    'keterangan' => $keterangan,
                ];

                continue;
            }

            $errors[] = ['sheet' => 'CSV', 'row' => $lineNumber, 'message' => "tipe_baris '{$tipeRaw}' tidak dikenali — harus OB, RINCIAN, atau ITEM."];
        }
        fclose($handle);

        $linked = $this->linkDetailsAndItems($obRows, $rawDetails, $rawItems);
        $errors = [...$errors, ...$linked['errors']];

        return [$obRows, $linked['detailsByOb'], $errors];
    }

    private function openCsvHandle(string $filePath)
    {
        $handle = fopen($filePath, 'r');
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        return $handle;
    }

    /** Sampling beberapa baris pertama — file template pakai ';' (locale Excel Indonesia), file lain mungkin pakai ','. */
    private function detectCsvDelimiter(string $filePath, int $sampleLines = 20): string
    {
        $handle = $this->openCsvHandle($filePath);
        $sample = '';
        for ($i = 0; $i < $sampleLines; $i++) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $sample .= $line;
        }
        fclose($handle);

        return substr_count($sample, ';') > substr_count($sample, ',') ? ';' : ',';
    }

    // ──────────────────────────────────────────────────────────────
    //  XLSX — 3 sheet (Data Opening Balance + Rincian Invoice Asal + Item Invoice Asal)
    // ──────────────────────────────────────────────────────────────

    /** @return array{0: array<int|string, array>, 1: array<int|string, array>, 2: array} [obRows keyed by no_urut, detailsByOb keyed by no_urut, errors] */
    private function parseXlsx(string $filePath): array
    {
        $errors = [];

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        try {
            $obRows = $this->parseObSheet($spreadsheet, $errors);
            $rawDetails = $this->parseDetailSheet($spreadsheet, $errors);
            $rawItems = $this->parseItemSheet($spreadsheet, $errors);

            $linked = $this->linkDetailsAndItems($obRows, $rawDetails, $rawItems);
            $errors = [...$errors, ...$linked['errors']];

            return [$obRows, $linked['detailsByOb'], $errors];
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    /**
     * Lampirkan item ke detail terkait (kunci gabungan no_urut_ob + no_invoice_asal,
     * case-insensitive), lalu deteksi baris "orphan" — detail/item yang referensinya
     * (no_urut_ob / no_invoice_asal) tidak cocok dengan baris manapun di level atasnya
     * — dan laporkan sebagai error alih-alih diam-diam diabaikan. Dipakai bersama oleh
     * parseCsv() dan parseXlsx() supaya logic linking tidak dobel dan hasilnya konsisten
     * apa pun format file sumbernya.
     *
     * Error dikembalikan lewat return (bukan by-ref) supaya method ini tetap pure/mudah
     * diuji — pola sama seperti resolveKlien().
     *
     * @param  array<int|string, array>  $obRows
     * @param  array<int|string, array>  $rawDetails  keyed by no_urut_ob, list detail (belum berisi 'items')
     * @param  array<string, array>  $rawItems  keyed by "no_urut_ob|lower(no_invoice_asal)", list item
     * @return array{detailsByOb: array<int|string, array>, errors: array} detailsByOb keyed by no_urut, tiap detail sudah berisi 'items'
     */
    private function linkDetailsAndItems(array $obRows, array $rawDetails, array $rawItems): array
    {
        $errors = [];

        foreach ($rawDetails as $noUrut => $details) {
            foreach ($details as $idx => $detail) {
                $key = $noUrut.'|'.strtolower(trim($detail['no_invoice_asal']));
                $rawDetails[$noUrut][$idx]['items'] = $rawItems[$key] ?? [];
                unset($rawItems[$key]);
            }
        }

        // Sisa $rawItems setelah dilampirkan = item yang no_urut_ob/no_invoice_asal-nya tidak
        // cocok dengan baris manapun di Rincian Invoice Asal — laporkan sbg error baris.
        foreach ($rawItems as $orphanItems) {
            foreach ($orphanItems as $it) {
                $errors[] = [
                    'sheet' => 'Item Invoice Asal',
                    'row' => $it['source_row'],
                    'message' => 'no_urut_ob + no_invoice_asal tidak cocok dengan baris manapun di Rincian Invoice Asal.',
                ];
            }
        }

        // Detail yang no_urut_ob-nya tidak cocok OB manapun → orphan, laporkan & buang.
        foreach ($rawDetails as $noUrut => $details) {
            if (! isset($obRows[$noUrut])) {
                foreach ($details as $d) {
                    $errors[] = [
                        'sheet' => 'Rincian Invoice Asal',
                        'row' => $d['source_row'],
                        'message' => "no_urut_ob {$noUrut} tidak cocok dengan baris manapun di Data Opening Balance.",
                    ];
                }
                unset($rawDetails[$noUrut]);
            }
        }

        return ['detailsByOb' => $rawDetails, 'errors' => $errors];
    }

    /** @return array<int|string, array> keyed by no_urut */
    private function parseObSheet(Spreadsheet $spreadsheet, array &$errors): array
    {
        $sheetIndex = $this->findSheetIndex($spreadsheet, 'Data Opening Balance');
        if ($sheetIndex === null) {
            $errors[] = ['sheet' => 'Data Opening Balance', 'row' => 0, 'message' => 'Sheet "Data Opening Balance" tidak ditemukan dalam file.'];

            return [];
        }

        $sheet = $spreadsheet->getSheet($sheetIndex);
        $detected = $this->detectHeaderStart($sheet, 'kode_klien', 6);
        if (! $detected['found']) {
            $errors[] = ['sheet' => 'Data Opening Balance', 'row' => 0, 'message' => 'Header "kode_klien" tidak ditemukan di sheet Data Opening Balance.'];

            return [];
        }

        $obRows = [];
        $this->eachDataRow($sheet, $detected, function (array $row, int $lineNumber) use (&$obRows, &$errors) {
            $kodeKlienRaw = trim($row[0] ?? '');
            if (str_starts_with($kodeKlienRaw, '[CONTOH]') || str_starts_with($kodeKlienRaw, '#')) {
                return;
            }

            $namaKlien = trim($row[1] ?? '');
            $noUrutRaw = trim($row[5] ?? '');
            if ($kodeKlienRaw === '' && $namaKlien === '' && $noUrutRaw === '') {
                return;
            } // baris kosong

            if ($noUrutRaw === '') {
                $errors[] = ['sheet' => 'Data Opening Balance', 'row' => $lineNumber, 'message' => 'no_urut wajib diisi.'];

                return;
            }
            if ($namaKlien === '') {
                $errors[] = ['sheet' => 'Data Opening Balance', 'row' => $lineNumber, 'message' => 'nama_klien wajib diisi.'];

                return;
            }
            if (isset($obRows[$noUrutRaw])) {
                $errors[] = ['sheet' => 'Data Opening Balance', 'row' => $lineNumber, 'message' => "no_urut '{$noUrutRaw}' duplikat (sudah dipakai baris lain)."];

                return;
            }

            $obRows[$noUrutRaw] = [
                'sheet' => 'Data Opening Balance',
                'source_row' => $lineNumber,
                'kode_klien' => $this->importValue($kodeKlienRaw),
                'nama_klien' => $namaKlien,
                'tanggal' => $this->importDate($row[2] ?? null),
                'saldo_awal' => $this->importNum($row[3] ?? null),
                'keterangan' => $this->importValue($row[4] ?? null),
            ];
        });

        return $obRows;
    }

    /** @return array<int|string, array> keyed by no_urut_ob, list of detail rows (belum berisi 'items') */
    private function parseDetailSheet(Spreadsheet $spreadsheet, array &$errors): array
    {
        $sheetIndex = $this->findSheetIndex($spreadsheet, 'Rincian Invoice Asal');
        if ($sheetIndex === null) {
            return [];
        }

        $sheet = $spreadsheet->getSheet($sheetIndex);
        $detected = $this->detectHeaderStart($sheet, 'no_urut_ob', 7);
        if (! $detected['found']) {
            return [];
        }

        $details = [];
        $this->eachDataRow($sheet, $detected, function (array $row, int $lineNumber) use (&$details, &$errors) {
            $noUrutOb = trim($row[0] ?? '');
            $deskripsi = trim($row[3] ?? '');
            if (str_starts_with($deskripsi, '[CONTOH]')) {
                return;
            }
            if ($noUrutOb === '' && trim($row[1] ?? '') === '') {
                return;
            } // baris kosong

            $noInvoiceAsal = trim($row[1] ?? '');
            if ($noUrutOb === '' || $noInvoiceAsal === '') {
                $errors[] = ['sheet' => 'Rincian Invoice Asal', 'row' => $lineNumber, 'message' => 'no_urut_ob dan no_invoice_asal wajib diisi.'];

                return;
            }

            $tanggalAsal = $this->importDate($row[2] ?? null);
            $sisaTagihan = $this->importNum($row[5] ?? null);

            if (! $tanggalAsal || $deskripsi === '' || $sisaTagihan <= 0) {
                $errors[] = ['sheet' => 'Rincian Invoice Asal', 'row' => $lineNumber, 'message' => 'tanggal_invoice_asal, deskripsi wajib diisi, dan sisa_tagihan_asal harus lebih dari 0.'];

                return;
            }

            $details[$noUrutOb][] = [
                'source_row' => $lineNumber,
                'no_invoice_asal' => $noInvoiceAsal,
                'tanggal_invoice_asal' => $tanggalAsal,
                'deskripsi' => $deskripsi,
                'jumlah_tagihan_asal' => $this->importNum($row[4] ?? null),
                'sisa_tagihan_asal' => $sisaTagihan,
                'keterangan' => $this->importValue($row[6] ?? null),
            ];
        });

        return $details;
    }

    /** @return array<string, array> keyed by "no_urut_ob|lower(no_invoice_asal)", list of item rows */
    private function parseItemSheet(Spreadsheet $spreadsheet, array &$errors): array
    {
        $sheetIndex = $this->findSheetIndex($spreadsheet, 'Item Invoice Asal');
        if ($sheetIndex === null) {
            return [];
        }

        $sheet = $spreadsheet->getSheet($sheetIndex);
        $detected = $this->detectHeaderStart($sheet, 'no_urut_ob', 9);
        if (! $detected['found']) {
            return [];
        }

        $items = [];
        $this->eachDataRow($sheet, $detected, function (array $row, int $lineNumber) use (&$items, &$errors) {
            $noUrutOb = trim($row[0] ?? '');
            $namaBarang = trim($row[3] ?? '');
            if (str_starts_with($namaBarang, '[CONTOH]')) {
                return;
            }
            if ($noUrutOb === '' && trim($row[1] ?? '') === '') {
                return;
            } // baris kosong

            $noInvoiceAsal = trim($row[1] ?? '');
            $qty = $this->importNum($row[4] ?? null);
            $hargaSatuan = $this->importNum($row[6] ?? null);

            if ($noUrutOb === '' || $noInvoiceAsal === '' || $namaBarang === '' || $qty <= 0) {
                $errors[] = ['sheet' => 'Item Invoice Asal', 'row' => $lineNumber, 'message' => 'no_urut_ob, no_invoice_asal, nama_barang wajib diisi, dan qty harus lebih dari 0.'];

                return;
            }

            $key = $noUrutOb.'|'.strtolower(trim($noInvoiceAsal));
            $items[$key][] = [
                'source_row' => $lineNumber,
                'kode_barang' => $this->importValue($row[2] ?? null),
                'nama_barang' => $namaBarang,
                'qty' => $qty,
                'satuan' => $this->importValue($row[5] ?? null),
                'harga_satuan' => $hargaSatuan,
                'subtotal' => $this->importNum($row[7] ?? null),
                'keterangan' => $this->importValue($row[8] ?? null),
            ];
        });

        return $items;
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers XLSX
    // ──────────────────────────────────────────────────────────────

    private function findSheetIndex(Spreadsheet $spreadsheet, string $name): ?int
    {
        foreach ($spreadsheet->getSheetNames() as $i => $sheetName) {
            if (strtolower(trim($sheetName)) === strtolower($name)) {
                return $i;
            }
        }

        return null;
    }

    /** @return array{found: bool, dataStart: int, highestColumn: string, highestRow: int} */
    private function detectHeaderStart(Worksheet $sheet, string $headerFirstCol, int $maxCols, int $previewRows = 30): array
    {
        $highestRow = $sheet->getHighestRow();
        $maxColLetter = Coordinate::stringFromColumnIndex($maxCols);
        $previewEnd = min($previewRows, $highestRow);

        $preview = $sheet->rangeToArray("A1:{$maxColLetter}{$previewEnd}", null, true, false, false);

        foreach ($preview as $idx => $cells) {
            $firstCell = trim($this->cellToString($cells[0] ?? ''));
            if ($this->normalizeHeaderName($firstCell) === strtolower($headerFirstCol)) {
                return [
                    'found' => true,
                    'dataStart' => $idx + 2,
                    'highestColumn' => $maxColLetter,
                    'highestRow' => $highestRow,
                ];
            }
        }

        return ['found' => false, 'dataStart' => 0, 'highestColumn' => $maxColLetter, 'highestRow' => $highestRow];
    }

    /** Baca seluruh baris data (dataStart..highestRow) sekaligus — volume XLSX yang didukung fitur ini realistis kecil-menengah. */
    private function eachDataRow(Worksheet $sheet, array $detected, callable $onRow): void
    {
        if ($detected['highestRow'] < $detected['dataStart']) {
            return;
        }

        $rows = $sheet->rangeToArray(
            "A{$detected['dataStart']}:{$detected['highestColumn']}{$detected['highestRow']}",
            null, true, false, false,
        );

        foreach ($rows as $idx => $rawRow) {
            $lineNumber = $detected['dataStart'] + $idx;
            $onRow(array_map(fn ($c) => $this->cellToString($c), $rawRow), $lineNumber);
        }
    }

    private function cellToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return fmod($value, 1.0) === 0.0 ? sprintf('%.0f', $value) : (string) $value;
        }

        return trim((string) $value);
    }

    private function normalizeHeaderName(string $raw): string
    {
        return trim(preg_replace('/\(\s*\*\s*\)\s*$/', '', strtolower(trim($raw))));
    }

    private function importValue(mixed $val): ?string
    {
        $s = trim((string) $val);

        return ($s === '' || $s === '-') ? null : $s;
    }

    private function importDate(mixed $val): ?string
    {
        $s = trim((string) $val);
        if ($s === '' || $s === '-') {
            return null;
        }

        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (is_numeric($s)) {
            try {
                return PhpSpreadsheetDate::excelToDateTimeObject((float) $s)->format('Y-m-d');
            } catch (Throwable) {
                return $s;
            }
        }

        return $s;
    }

    private function importNum(mixed $val): float
    {
        $s = str_replace(['.', ','], ['', '.'], trim((string) $val));

        return is_numeric($s) ? (float) $s : 0.0;
    }
}
