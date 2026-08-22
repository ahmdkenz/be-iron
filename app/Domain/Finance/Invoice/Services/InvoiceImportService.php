<?php

namespace App\Domain\Finance\Invoice\Services;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceSyncBatcher;
use App\Models\Barang;
use App\Models\BankStatementDetail;
use App\Models\Invoice;
use App\Models\InvoiceImportBatch;
use App\Models\InvoiceImportGroup;
use App\Models\InvoiceImportRow;
use App\Models\KlienAr;
use App\Models\PembayaranAr;
use App\Models\Resto;
use App\Support\Exceptions\ImportCancelledException;
use App\Support\Helpers\ImportDateParser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Import invoice dari file Excel dengan alur AMAN:
 *
 *   upload → parse → klasifikasi → (berhenti, tunggu konfirmasi user)
 *          → "Proses Data Aman"  → tulis NEW_INVOICE & SAFE_UPDATE
 *                                → catat hasilnya ke tb_invoice_import_change_log
 *
 * Prinsipnya: import hanya menolak menyentuh invoice pada dua kondisi keras —
 * periode sudah TERKUNCI di Ending Balance, atau invoice sudah LUNAS. Selain itu
 * (TERKIRIM / SEBAGIAN) invoice diperbarui langsung mengikuti file.
 *
 * Update TIDAK pernah menyentuh tb_pembayaran_ar, jadi pembayaran & no. referensi
 * yang sudah ada tetap utuh; InvoiceGroupProcessor::updateInvoice() hanya mengganti
 * item lalu recalculate(), sehingga sisa tagihan mengikuti nilai terbaru secara
 * dinamis (naik → sisa muncul lagi & bisa dialokasikan dari Cocokkan Transaksi,
 * turun di bawah total bayar → kelebihannya otomatis jadi PDM).
 *
 * Credit Note / Debit Note TIDAK pernah dibuat otomatis oleh import — seluruhnya
 * manual lewat menu Ending Balance → Koreksi.
 *
 * Berbeda dengan MasterImportService yang langsung menulis begitu file diproses.
 */
class InvoiceImportService
{
    /**
     * Jumlah grup yang diproses per job chunk saat "Proses Data Aman". Dinaikkan dari 50 ke
     * 200 (2026-08-07) setelah kerja per-grup di applySafeChunk()/applyGroup() jadi O(1) query
     * (preload klien+rows per chunk, EB batching) — chunk lebih besar = lebih sedikit overhead
     * job dispatch/reserve di queue database per import.
     */
    public const APPLY_CHUNK = 200;

    /** Kolom sheet MASTER INVOICE (0-based, harus sinkron dengan InvoiceImportTemplateService). */
    private const COL_NAMA_KLIEN          = 0;
    private const COL_TANGGAL_INVOICE     = 1;
    private const COL_TANGGAL_JATUH_TEMPO = 2;
    private const COL_NO_SURAT_JALAN      = 3;
    private const COL_KETERANGAN          = 4;
    private const COL_NO_INVOICE_RESTO    = 5;
    private const COL_KODE_RESTO          = 6;
    private const COL_NAMA_RESTO          = 7;
    private const COL_KODE_BARANG         = 8;
    private const COL_NAMA_BARANG         = 9;
    private const COL_QTY                 = 10;
    private const COL_SATUAN              = 11;
    private const COL_HARGA_SATUAN        = 12;
    private const COL_TIPE_INVOICE        = 13;

    private const MAX_COLS = 14;

    /** Jumlah baris per chunk saat membaca sheet xlsx (lihat MasterImportService::PARSE_CHUNK). */
    private const PARSE_CHUNK = 1000;

    /**
     * Kode error kalkulasi Excel standar → penjelasan bahasa awam. Rumus yang gagal (mis. =A1/0)
     * dikembalikan PhpSpreadsheet sebagai string kode ini (bukan exception), jadi kalau tidak
     * dideteksi khusus akan lolos ke importNum()/importDate() dan diam-diam jadi 0/null.
     */
    private const EXCEL_FORMULA_ERRORS = [
        '#DIV/0!'       => 'rumus melakukan pembagian dengan angka nol',
        '#REF!'         => 'rumus merujuk ke sel/baris/kolom yang sudah dihapus atau tidak valid',
        '#VALUE!'       => 'rumus mendapat jenis data yang salah (misalnya teks dihitung seperti angka)',
        '#NAME?'        => 'rumus menggunakan nama fungsi atau referensi yang tidak dikenali Excel',
        '#NULL!'        => 'rumus merujuk ke perpotongan sel yang tidak valid',
        '#NUM!'         => 'rumus menghasilkan angka yang tidak valid (terlalu besar/kecil atau tidak masuk akal)',
        '#N/A'          => 'rumus tidak menemukan data yang dicari (misalnya VLOOKUP gagal menemukan hasil)',
        '#GETTING_DATA' => 'rumus sedang mengambil data dari sumber luar yang tidak tersedia',
        '#SPILL!'       => 'hasil rumus tumpah ke sel lain yang sudah terisi data',
        '#CALC!'        => 'rumus gagal dihitung oleh Excel',
    ];

    /**
     * Label manusiawi untuk tiap risk flag. Flag-nya sendiri TIDAK lagi menentukan
     * klasifikasi (lihat classifyGroup()) — hanya disimpan di kolom risk_flags dan
     * dipakai memperjelas alasan grup yang ditolak.
     */
    private const RISK_LABELS = [
        'PEMBAYARAN'      => 'sudah menerima pembayaran',
        'NO_REFERENSI'    => 'pembayaran sudah punya no. referensi',
        'BANK_MATCHED'    => 'sudah dicocokkan dengan rekening koran',
        'PENYESUAIAN'     => 'sudah punya penyesuaian CN/DN',
        'STATUS_SEBAGIAN' => 'status SEBAGIAN',
        'LUNAS'           => 'status LUNAS',
        'EB_LOCKED'       => 'periode terkunci di Ending Balance',
        'KOREKSI_PENDING' => 'ada koreksi yang masih menunggu persetujuan',
    ];

    /** Baris riwayat perubahan per INSERT statement (mirror OpeningBalanceImportService). */
    private const CHANGE_LOG_FLUSH_SIZE = 500;

    public function __construct(
        private readonly InvoiceGroupProcessor $groupProcessor,
        private readonly InvoiceService        $invoiceService,
    ) {}

    // ══════════════════════════════════════════════════════════════
    //  FASE 1 — Parsing
    // ══════════════════════════════════════════════════════════════

    /**
     * Baca file, validasi tiap baris terhadap MASTER DATA, resolve Client AR,
     * lalu simpan hasil grouping ke tb_invoice_import_groups / _rows.
     *
     * Tidak menulis apa pun ke tb_invoice.
     */
    public function parse(InvoiceImportBatch $batch): void
    {
        $disk = Storage::disk('local');
        if (!$batch->file_path || !$disk->exists($batch->file_path)) {
            throw new \RuntimeException("File import tidak ditemukan: {$batch->file_path}");
        }

        $batch->update(['status' => 'parsing', 'phase' => 'parsing_file', 'started_at' => now()]);

        $filePath = $disk->path($batch->file_path);
        $ext      = strtolower(pathinfo($batch->original_filename, PATHINFO_EXTENSION));

        // Preload acuan master — dipakai kedua jalur (CSV & xlsx), tidak tergantung format file.
        [$namaMap, $restoMap, $restoNameMap, $restoNameCount] = $this->buildKlienMaps();
        $maps = [
            'namaMap'        => $namaMap,
            'restoMap'       => $restoMap,
            'restoNameMap'   => $restoNameMap,
            'restoNameCount' => $restoNameCount,
            'restoMasterMap' => $this->buildRestoMasterMap(),
            'barangMap'      => $this->buildBarangMap(),
        ];

        try {
            $result = in_array($ext, ['xlsx', 'xls'], true)
                ? $this->parseXlsxFile($filePath, $batch, $maps)
                : $this->parseCsvFile($filePath, $batch, $maps);

            $this->persistGroups($batch, $result['groups']);
        } catch (ImportCancelledException) {
            $batch->cancel(sprintf('Dibatalkan oleh %s saat parsing.', $batch->cancelRequestedBy() ?? 'pengguna'));

            return;
        }

        $batch->update([
            'parsed_rows'  => $result['parsedRows'],
            'total_groups' => count($result['groups']),
            'errors'       => $result['errors'],
            'phase'        => 'parsed',
        ]);
    }

    /** @return array{groups: array, errors: array, parsedRows: int} */
    private function parseCsvFile(string $filePath, InvoiceImportBatch $batch, array $maps): array
    {
        $errors     = [];
        $groups     = [];
        $parsedRows = 0;
        $lineNumber = 1; // header sudah "dikonsumsi" secara virtual (lihat scanCsvHeaderAndCount)

        $scan = $this->scanCsvHeaderAndCount($filePath);
        $batch->update([
            'total_rows' => $scan['totalRows'],
            'phase'      => 'parsing_rows',
        ]);

        $this->chunkCsvRows(
            $filePath, $scan['headerLineIdx'] + 1, $scan['delimiter'],
            function (array $row) use (&$lineNumber, &$errors, &$groups, &$parsedRows, $maps, $batch) {
                $lineNumber++;

                // Progres bertahap — batch->update() akhir baru menulis parsed_rows
                // setelah SELURUH file selesai dibaca, jadi tanpa ini FE/DB akan
                // menunjukkan 0 dari total_rows terus selama proses berjalan
                // (terlihat identik dengan job yang macet).
                if ($lineNumber % 2000 === 0) {
                    $batch->update(['parsed_rows' => $parsedRows]);
                    if ($batch->isCancelRequested()) {
                        throw new ImportCancelledException();
                    }
                }

                if ($this->processInvoiceRow($row, $lineNumber, $maps, $groups, $errors)) {
                    $parsedRows++;
                }
            },
        );

        return ['groups' => $groups, 'errors' => $errors, 'parsedRows' => $parsedRows];
    }

    /** @return array{groups: array, errors: array, parsedRows: int} */
    private function parseXlsxFile(string $filePath, InvoiceImportBatch $batch, array $maps): array
    {
        $errors     = [];
        $groups     = [];
        $parsedRows = 0;

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        $sheetIndex = $this->findSheetIndex($spreadsheet, 'MASTER INVOICE');
        if ($sheetIndex === null) {
            throw new \RuntimeException('Sheet "MASTER INVOICE" tidak ditemukan pada file xlsx.');
        }
        $sheet = $spreadsheet->getSheet($sheetIndex);

        $header = $this->detectInvoiceHeaderStart($sheet);
        if (!$header['found']) {
            throw new \RuntimeException('Header "nama_klien" tidak ditemukan pada sheet MASTER INVOICE.');
        }

        $batch->update([
            'total_rows' => max(0, $header['highestRow'] - $header['dataStart'] + 1),
            'phase'      => 'parsing_rows',
        ]);

        $this->chunkXlsxRows(
            $sheet, $header['dataStart'], $header['highestColumn'], self::PARSE_CHUNK,
            function (array $row, int $excelRow) use (&$errors, &$groups, &$parsedRows, $maps, $batch) {
                // Progres bertahap — lihat komentar sepadan di parseCsvFile().
                if ($excelRow % 2000 === 0) {
                    $batch->update(['parsed_rows' => $parsedRows]);
                    if ($batch->isCancelRequested()) {
                        throw new ImportCancelledException();
                    }
                }

                if ($this->processInvoiceRow($row, $excelRow, $maps, $groups, $errors)) {
                    $parsedRows++;
                }
            },
        );

        return ['groups' => $groups, 'errors' => $errors, 'parsedRows' => $parsedRows];
    }

    /**
     * Inti validasi & grouping satu baris — dipakai baik oleh jalur CSV maupun xlsx supaya
     * business logic (validasi wajib, validasi vs MASTER DATA, resolve klien, grouping) tidak
     * terduplikasi antar format. $row harus array 0-based sepanjang MAX_COLS mengikuti urutan
     * kolom self::COL_*, apa pun format sumbernya.
     *
     * @param  array<string, array> $maps ['namaMap','restoMap','restoNameMap','restoNameCount','restoMasterMap','barangMap']
     * @return bool true jika baris dihitung sebagai "terbaca" (bukan baris komentar/[CONTOH]/kosong)
     */
    private function processInvoiceRow(array $row, int $lineNumber, array $maps, array &$groups, array &$errors): bool
    {
        $firstCell = trim((string) ($row[self::COL_NAMA_KLIEN] ?? ''));
        $tipeCell  = trim((string) ($row[self::COL_TIPE_INVOICE] ?? ''));
        if (str_starts_with($firstCell, '#')) return false;
        if (str_starts_with($tipeCell, '[CONTOH]')) return false;
        if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) return false;

        if ($formulaError = $this->detectFormulaError($row, 'Data Sheet', $lineNumber)) {
            $errors[] = ['row' => $lineNumber, 'message' => $formulaError];
            return true;
        }

        $tipeInvoice = strtoupper(trim((string) ($row[self::COL_TIPE_INVOICE] ?? '')));
        $namaKlien   = $this->importValue($row[self::COL_NAMA_KLIEN] ?? '');
        $tanggal     = $this->importDate($row[self::COL_TANGGAL_INVOICE] ?? '');
        $kodeResto   = $this->importValue($row[self::COL_KODE_RESTO] ?? '');

        if (!$tipeInvoice || !$tanggal) {
            $errors[] = ['row' => $lineNumber, 'message' => 'tipe_invoice dan tanggal_invoice wajib diisi.'];
            return true;
        }
        if (!in_array($tipeInvoice, ['B2B', 'B2C'], true)) {
            $errors[] = ['row' => $lineNumber, 'message' => "tipe_invoice '{$tipeInvoice}' tidak valid. Harus 'B2B' atau 'B2C'."];
            return true;
        }
        // nama_klien wajib untuk B2B (resolve via nama) dan B2C tanpa kode_resto (tidak ada
        // acuan lain). B2C DENGAN kode_resto boleh kosong — resolve murni via kode_resto ke
        // MASTER DATA (lihat validateRowAgainstMasterData()/resolveKlienForRow()).
        if (!$namaKlien && !($tipeInvoice === 'B2C' && $kodeResto)) {
            $errors[] = ['row' => $lineNumber, 'message' => 'nama_klien wajib diisi' . ($tipeInvoice === 'B2C' ? ' (atau isi kode_resto).' : '.')];
            return true;
        }

        $masterError = $this->validateRowAgainstMasterData($tipeInvoice, $namaKlien ?? '', $kodeResto, $maps['restoMasterMap']);
        if ($masterError) {
            $errors[] = ['row' => $lineNumber, 'message' => $masterError];
            return true;
        }

        [$klien, $klienError] = $this->resolveKlienForRow(
            $tipeInvoice, $namaKlien ?? '', $kodeResto,
            $maps['namaMap'], $maps['restoMap'], $maps['restoNameMap'], $maps['restoNameCount'],
        );
        if (!$klien) {
            $errors[] = ['row' => $lineNumber, 'message' => $klienError];
            return true;
        }

        $key = $tipeInvoice . '||' . $klien->id . '||' . $tanggal;

        if (!isset($groups[$key])) {
            $groups[$key] = [
                'group_key'           => $key,
                'tipe_invoice'        => $tipeInvoice,
                'nama_klien'          => $namaKlien,
                'kode_resto'          => $kodeResto,
                'klien_ar_id'         => $klien->id,
                'tanggal_invoice'     => $tanggal,
                'tanggal_jatuh_tempo' => $this->importDate($row[self::COL_TANGGAL_JATUH_TEMPO] ?? ''),
                'no_surat_jalan'      => $this->importValue($row[self::COL_NO_SURAT_JALAN] ?? ''),
                'keterangan'          => $this->importValue($row[self::COL_KETERANGAN] ?? ''),
                'first_line'          => $lineNumber,
                'items'               => [],
                'row_errors'          => [],
            ];
        }

        $namaBarang = $this->importValue($row[self::COL_NAMA_BARANG] ?? '');
        $qty        = $this->importNum($row[self::COL_QTY] ?? '');
        $harga      = $this->importNum($row[self::COL_HARGA_SATUAN] ?? '');
        $kodeBarang = $this->importValue($row[self::COL_KODE_BARANG] ?? '');

        $itemError = match (true) {
            !$namaBarang => 'nama_barang wajib diisi.',
            $qty <= 0    => 'qty harus lebih dari 0.',
            default      => null,
        };
        if ($itemError) {
            $groups[$key]['row_errors'][] = "Baris {$lineNumber}: {$itemError}";
        }

        $groups[$key]['items'][] = [
            'row_number'       => $lineNumber,
            'no_invoice_resto' => $this->importValue($row[self::COL_NO_INVOICE_RESTO] ?? ''),
            'kode_resto'       => $kodeResto,
            'nama_resto'       => $this->importValue($row[self::COL_NAMA_RESTO] ?? ''),
            'kode_barang'      => $kodeBarang,
            'nama_barang'      => $namaBarang,
            'barang_id'        => $kodeBarang ? ($maps['barangMap'][strtoupper($kodeBarang)] ?? null) : null,
            'qty'              => $qty,
            'satuan'           => $this->importValue($row[self::COL_SATUAN] ?? ''),
            'harga_satuan'     => $harga,
            'subtotal'         => round($qty * $harga, 2),
            'error'            => $itemError,
        ];

        return true;
    }

    /** Simpan hasil grouping (grup + item) ke tabel staging. */
    private function persistGroups(InvoiceImportBatch $batch, array $groups): void
    {
        // total_groups sudah diketahui di sini (grouping selesai di memori sebelum
        // method ini dipanggil) — ditulis lebih awal supaya FE tidak menunggu sampai
        // seluruh proses tulis selesai untuk tahu target totalnya.
        $batch->update(['total_groups' => count($groups)]);

        foreach (array_chunk($groups, 100, true) as $chunk) {
            if ($batch->isCancelRequested()) {
                throw new ImportCancelledException();
            }

            DB::transaction(function () use ($batch, $chunk) {
                $now          = now();
                $rowsToInsert = [];

                foreach ($chunk as $group) {
                    $rejected = !empty($group['row_errors']) || empty($group['items']);
                    $reason   = match (true) {
                        empty($group['items'])       => 'Invoice tidak memiliki item.',
                        !empty($group['row_errors']) => implode(' ', $group['row_errors']),
                        default                      => null,
                    };

                    $model = InvoiceImportGroup::create([
                        'batch_id'            => $batch->id,
                        'group_key'           => $group['group_key'],
                        'tipe_invoice'        => $group['tipe_invoice'],
                        'nama_klien'          => $group['nama_klien'],
                        'kode_resto'          => $group['kode_resto'],
                        'tanggal_invoice'     => $group['tanggal_invoice'],
                        'tanggal_jatuh_tempo' => $group['tanggal_jatuh_tempo'],
                        'no_surat_jalan'      => $group['no_surat_jalan'],
                        'keterangan'          => $group['keterangan'],
                        'first_line'          => $group['first_line'],
                        'item_count'          => count($group['items']),
                        'klien_ar_id'         => $group['klien_ar_id'],
                        'classification'      => $rejected ? 'REJECTED' : 'NEW_INVOICE',
                        'reason'              => $reason,
                        'total_baru'          => array_sum(array_column($group['items'], 'subtotal')),
                    ]);

                    foreach ($group['items'] as $item) {
                        $rowsToInsert[] = [
                            'batch_id'   => $batch->id,
                            'group_id'   => $model->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ] + $item;
                    }
                }

                if (!empty($rowsToInsert)) {
                    InvoiceImportRow::insert($rowsToInsert);
                }
            });
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  FASE 2 — Klasifikasi
    // ══════════════════════════════════════════════════════════════

    /**
     * Bandingkan tiap grup dengan invoice existing dan tentukan klasifikasinya.
     * Setelah selesai batch berhenti di status awaiting_review — belum ada satu pun
     * invoice yang ditulis.
     */
    public function classify(InvoiceImportBatch $batch): void
    {
        $batch->update(['status' => 'classifying', 'phase' => 'classifying']);

        $lockedEbMap = $this->groupProcessor->buildLockedEbMap();
        // cnt_review_required dipertahankan supaya set counter klasifikasi tetap utuh,
        // tapi sejak REVIEW_REQUIRED tidak pernah dihasilkan lagi nilainya selalu 0.
        $counters    = [
            'cnt_new' => 0, 'cnt_unchanged' => 0, 'cnt_safe_update' => 0,
            'cnt_review_required' => 0, 'cnt_rejected' => 0,
        ];
        $done = 0;

        InvoiceImportGroup::where('batch_id', $batch->id)
            ->orderBy('id')
            ->chunkById(100, function ($groups) use ($batch, $lockedEbMap, &$counters, &$done) {
                if ($batch->isCancelRequested()) {
                    return false;
                }

                $snapshots = $this->buildExistingSnapshots($groups);
                $rowsByGroup = InvoiceImportRow::whereIn('group_id', $groups->pluck('id'))
                    ->orderBy('row_number')
                    ->get()
                    ->groupBy('group_id');

                $now = now();
                $rowsToUpsert = [];

                foreach ($groups as $group) {
                    if ($group->classification === 'REJECTED') {
                        $counters['cnt_rejected']++;
                        $done++;
                        continue;
                    }

                    $incoming = [
                        'items'  => $this->rowsToItems($rowsByGroup[$group->id] ?? collect()),
                        'header' => [
                            'tanggal_jatuh_tempo' => $this->dateOrNull($group->tanggal_jatuh_tempo),
                            'no_surat_jalan'      => $group->no_surat_jalan,
                            'keterangan'          => $group->keterangan,
                        ],
                    ];

                    $tanggal  = $this->dateOrNull($group->tanggal_invoice) ?? '';
                    $existing = $snapshots[$group->klien_ar_id . '|' . $tanggal] ?? null;
                    $ebLocked = $this->groupProcessor->isEbLocked($lockedEbMap, (int) $group->klien_ar_id, $tanggal);

                    $result = $this->classifyGroup($incoming, $existing, $ebLocked);

                    $rowsToUpsert[] = [
                        'id'             => $group->id,
                        'batch_id'       => $batch->id,
                        'group_key'      => $group->group_key,
                        'classification' => $result['classification'],
                        'reason'         => $result['reason'],
                        'risk_flags'     => json_encode($result['risk_flags']),
                        'header_diff'    => json_encode($result['header_diff']),
                        'total_lama'     => $result['total_lama'],
                        'total_baru'     => $result['total_baru'],
                        'selisih'        => $result['selisih'],
                        'invoice_id'     => $existing['id']         ?? null,
                        'no_invoice'     => $existing['no_invoice'] ?? null,
                        'updated_at'     => $now,
                    ];

                    $counters[match ($result['classification']) {
                        'NEW_INVOICE' => 'cnt_new',
                        'UNCHANGED'   => 'cnt_unchanged',
                        'SAFE_UPDATE' => 'cnt_safe_update',
                        default       => 'cnt_rejected',
                    }]++;

                    $done++;
                }

                if (!empty($rowsToUpsert)) {
                    InvoiceImportGroup::upsert(
                        $rowsToUpsert,
                        ['id'],
                        ['classification', 'reason', 'risk_flags', 'header_diff', 'total_lama', 'total_baru',
                            'selisih', 'invoice_id', 'no_invoice', 'updated_at'],
                    );
                }

                $batch->update(['classified_groups' => $done] + $counters);
            });

        if ($batch->isCancelRequested()) {
            $batch->cancel(sprintf('Dibatalkan oleh %s saat klasifikasi.', $batch->cancelRequestedBy() ?? 'pengguna'));

            return;
        }

        // Tidak ada satu invoice pun yang perlu ditulis (semua UNCHANGED/REJECTED, atau
        // file kosong) — batalkan otomatis alih-alih menggantung di awaiting_review dengan
        // tombol "Proses Data Aman (0)" yang percuma. Ini juga langsung membuka jalan
        // upload baru (lihat guard 409 di InvoiceImportController::store()), jadi reupload
        // data yang identik tidak perlu klik "Batalkan Import" manual dulu.
        if ($counters['cnt_new'] + $counters['cnt_safe_update'] === 0) {
            $batch->cancel($this->buildAutoCancelMessage($counters));

            return;
        }

        $batch->update([
            'status'            => 'awaiting_review',
            'phase'             => 'awaiting_review',
            'classified_groups' => $done,
            'classified_at'     => now(),
            'message'           => $this->buildClassificationMessage($counters, count($batch->errors ?? [])),
        ] + $counters);
    }

    /**
     * Inti aturan aman — MURNI (tanpa DB) supaya bisa diuji tanpa database.
     *
     * Hanya ada DUA kondisi keras yang membuat invoice tidak disentuh:
     *   1. periode TERKUNCI di Ending Balance, dan
     *   2. invoice sudah LUNAS.
     * Keduanya jadi REJECTED. Selain itu (TERKIRIM / SEBAGIAN) invoice diperbarui
     * langsung — termasuk yang sudah dibayar sebagian atau sudah dicocokkan dengan
     * rekening koran, karena update tidak menyentuh pembayaran/no. referensi dan
     * sisa tagihannya memang dirancang mengikuti nilai terbaru secara dinamis.
     *
     * Urutan REJECTED sengaja: EB terkunci dicek LEBIH DULU daripada LUNAS supaya
     * alasan yang tercatat selalu menyebut penyebab yang paling menentukan.
     *
     * @param array      $incoming ['items' => item[], 'header' => [...]]
     * @param array|null $existing Snapshot invoice existing (null = belum ada)
     * @param bool       $ebLocked Periode invoice ini terkunci di Ending Balance
     *
     * @return array{classification: string, reason: ?string, risk_flags: string[],
     *               total_lama: float, total_baru: float, selisih: float, header_diff: array}
     */
    public function classifyGroup(array $incoming, ?array $existing, bool $ebLocked): array
    {
        $totalBaru = round(array_sum(array_map(
            fn($i) => (float) $i['qty'] * (float) $i['harga_satuan'],
            $incoming['items'] ?? [],
        )), 2);

        // ── Belum ada invoice: hanya boleh dibuat kalau periodenya belum dikunci ──
        if ($existing === null) {
            if ($ebLocked) {
                return $this->result(
                    'REJECTED',
                    'Periode sudah dikunci di Ending Balance — invoice baru tidak dapat dibuat lewat import. Buka kunci EB terlebih dahulu.',
                    ['EB_LOCKED'], 0.0, $totalBaru, [],
                );
            }

            return $this->result('NEW_INVOICE', null, [], 0.0, $totalBaru, []);
        }

        // Netkan total_penyesuaian (CN/DN yang sudah disetujui) dari baseline —
        // subtotal invoice sengaja tidak pernah diubah saat CN/DN disetujui
        // (lihat EndingBalanceKoreksiService::applyPenyesuaian()), jadi tanpa ini
        // koreksi manual yang sudah approved akan selalu terdeteksi lagi sebagai
        // selisih baru pada import berikutnya.
        $totalLama  = round((float) ($existing['subtotal'] ?? 0) - (float) ($existing['total_penyesuaian'] ?? 0), 2);
        $selisih    = round($totalBaru - $totalLama, 2);
        $headerDiff = $this->diffHeader($existing['header'] ?? [], $incoming['header'] ?? []);
        $itemsSama  = $this->itemsSignature($existing['items'] ?? []) === $this->itemsSignature($incoming['items'] ?? []);

        // Tidak ada perubahan sama sekali → tidak perlu disentuh, apa pun kondisinya.
        if ($itemsSama && empty($headerDiff)) {
            return $this->result('UNCHANGED', 'Isi invoice sama dengan data existing.', [], $totalLama, $totalBaru, []);
        }

        // Selisih finansial sudah 0 dan sudah ada penyesuaian (CN/DN approved) yang
        // menutupnya — item invoice asli sengaja tidak pernah diubah saat CN/DN disetujui,
        // jadi item-level TIDAK akan pernah match lagi meski secara finansial sudah selesai.
        if ($selisih === 0.0 && empty($headerDiff) && round((float) ($existing['total_penyesuaian'] ?? 0), 2) !== 0.0) {
            return $this->result('UNCHANGED', 'Selisih sudah tertutup oleh CN/DN yang sudah disetujui sebelumnya.', [], $totalLama, $totalBaru, []);
        }

        $risk = $this->collectRiskFlags($existing, $ebLocked);

        // ── Kondisi keras 1: periode terkunci di Ending Balance ──
        // Selalu menang, tanpa pengecualian — termasuk untuk invoice SEBAGIAN yang
        // sudah dicocokkan rekening koran. Angka EB yang sudah dikunci tidak boleh
        // bergeser diam-diam lewat import; buka kunci EB dulu kalau memang mau diubah.
        if ($ebLocked) {
            return $this->result(
                'REJECTED',
                $this->buildRejectedReason($risk, 'Periode sudah dikunci di Ending Balance — buka kunci EB terlebih dahulu.'),
                $risk, $totalLama, $totalBaru, $headerDiff,
            );
        }

        // ── Kondisi keras 2: invoice sudah LUNAS ──
        // Invoice yang sudah selesai ditagih diperlakukan sebagai dokumen final;
        // perubahan nilainya harus lewat Credit/Debit Note manual di Ending Balance.
        if (($existing['status'] ?? null) === 'LUNAS') {
            return $this->result(
                'REJECTED',
                $this->buildRejectedReason($risk, 'Invoice sudah LUNAS — perubahan nilai harus lewat Credit/Debit Note manual di Ending Balance.'),
                $risk, $totalLama, $totalBaru, $headerDiff,
            );
        }

        // Sisanya (TERKIRIM / SEBAGIAN) diperbarui langsung. Pembayaran & no. referensi
        // yang sudah ada tidak disentuh; sisa tagihan dihitung ulang mengikuti nilai baru.
        return $this->result('SAFE_UPDATE', null, $risk, $totalLama, $totalBaru, $headerDiff);
    }

    /** @return string[] */
    private function collectRiskFlags(array $existing, bool $ebLocked): array
    {
        $flags = [];

        if (!empty($existing['has_pembayaran']) || (float) ($existing['total_pembayaran'] ?? 0) > 0) {
            $flags[] = 'PEMBAYARAN';
        }
        if (!empty($existing['has_no_referensi']))   $flags[] = 'NO_REFERENSI';
        if (!empty($existing['bank_matched']))       $flags[] = 'BANK_MATCHED';
        if (round((float) ($existing['total_penyesuaian'] ?? 0), 2) !== 0.0) $flags[] = 'PENYESUAIAN';
        if (($existing['status'] ?? null) === 'SEBAGIAN') $flags[] = 'STATUS_SEBAGIAN';
        if (($existing['status'] ?? null) === 'LUNAS')    $flags[] = 'LUNAS';
        if (!empty($existing['has_active_koreksi']))      $flags[] = 'KOREKSI_PENDING';
        if ($ebLocked)                                    $flags[] = 'EB_LOCKED';

        return $flags;
    }

    /**
     * Gabungkan sebab utama penolakan dengan konteks risk flag lain, supaya kolom
     * reason tetap menjelaskan kondisi invoice selengkapnya (mis. "sudah menerima
     * pembayaran, sudah dicocokkan dengan rekening koran").
     */
    private function buildRejectedReason(array $risk, string $sebabUtama): string
    {
        $labels = array_map(fn($f) => self::RISK_LABELS[$f] ?? $f, $risk);

        return empty($labels)
            ? $sebabUtama
            : $sebabUtama . ' Kondisi invoice: ' . implode(', ', $labels) . '.';
    }

    /** @return array{classification: string, ...} */
    private function result(
        string  $classification,
        ?string $reason,
        array   $riskFlags,
        float   $totalLama,
        float   $totalBaru,
        array   $headerDiff,
    ): array {
        return [
            'classification' => $classification,
            'reason'         => $reason,
            'risk_flags'     => $riskFlags,
            'total_lama'     => $totalLama,
            'total_baru'     => $totalBaru,
            'selisih'        => round($totalBaru - $totalLama, 2),
            'header_diff'    => $headerDiff,
        ];
    }

    /**
     * Tanda tangan isi item — dipakai membandingkan "sama atau berubah" tanpa
     * bergantung pada urutan baris di Excel.
     */
    public function itemsSignature(array $items): array
    {
        $sig = array_map(fn($i) => implode('|', [
            strtoupper(trim((string) ($i['kode_barang'] ?? ''))),
            strtolower(trim((string) ($i['nama_barang'] ?? ''))),
            number_format((float) ($i['qty'] ?? 0), 2, '.', ''),
            number_format((float) ($i['harga_satuan'] ?? 0), 2, '.', ''),
            strtolower(trim((string) ($i['satuan'] ?? ''))),
            strtolower(trim((string) ($i['no_invoice_resto'] ?? ''))),
            strtoupper(trim((string) ($i['kode_resto'] ?? ''))),
            strtolower(trim((string) ($i['nama_resto'] ?? ''))),
        ]), $items);

        sort($sig);

        return $sig;
    }

    /**
     * Bandingkan field header yang boleh berubah dari import.
     * @return array<string, array{lama: ?string, baru: ?string}>
     */
    public function diffHeader(array $lama, array $baru): array
    {
        $diff = [];

        foreach (['tanggal_jatuh_tempo', 'no_surat_jalan', 'keterangan'] as $field) {
            $a = $this->nullIfBlank($lama[$field] ?? null);
            $b = $this->nullIfBlank($baru[$field] ?? null);
            if ($a !== $b) {
                $diff[$field] = ['lama' => $a, 'baru' => $b];
            }
        }

        return $diff;
    }

    /**
     * Ambil snapshot invoice existing untuk sekumpulan grup dalam beberapa query
     * saja (hindari N+1 pada import besar).
     *
     * @param  \Illuminate\Support\Collection<int, InvoiceImportGroup> $groups
     * @return array<string, array> key: "klienArId|Y-m-d"
     */
    private function buildExistingSnapshots($groups): array
    {
        $klienIds = $groups->pluck('klien_ar_id')->filter()->unique()->values()->all();
        $tanggals = $groups->map(fn($g) => $this->dateOrNull($g->tanggal_invoice))->filter()->unique()->values()->all();

        if (empty($klienIds) || empty($tanggals)) {
            return [];
        }

        $invoices = Invoice::with('items')
            ->whereIn('klien_ar_id', $klienIds)
            ->whereIn('tanggal_invoice', $tanggals)
            ->where('is_opening_balance', false)
            ->get();

        if ($invoices->isEmpty()) {
            return [];
        }

        $invoiceIds = $invoices->pluck('id')->all();

        $pembayaran = PembayaranAr::whereIn('invoice_id', $invoiceIds)
            ->get(['id', 'invoice_id', 'no_referensi'])
            ->groupBy('invoice_id');

        $matchedPembayaranIds = BankStatementDetail::whereIn(
            'pembayaran_ar_id',
            $pembayaran->flatten()->pluck('id')->all() ?: [0],
        )->pluck('pembayaran_ar_id')->unique()->flip();

        $koreksiAktif = DB::table('tb_ending_balance_koreksi')
            ->whereIn('invoice_id', $invoiceIds)
            ->whereIn('status', ['PENDING_SPV', 'PENDING_MANAGER'])
            ->pluck('invoice_id')
            ->unique()
            ->flip();

        $snapshots = [];
        foreach ($invoices as $invoice) {
            $bayar = $pembayaran[$invoice->id] ?? collect();

            $snapshots[$invoice->klien_ar_id . '|' . Carbon::parse($invoice->tanggal_invoice)->toDateString()] = [
                'id'                 => $invoice->id,
                'no_invoice'         => $invoice->no_invoice,
                'status'             => $invoice->status,
                'subtotal'           => (float) $invoice->subtotal,
                'total_pembayaran'   => (float) $invoice->total_pembayaran,
                'total_penyesuaian'  => (float) $invoice->total_penyesuaian,
                'has_pembayaran'     => $bayar->isNotEmpty(),
                'has_no_referensi'   => $bayar->contains(fn($p) => filled($p->no_referensi)),
                'bank_matched'       => $bayar->contains(fn($p) => $matchedPembayaranIds->has($p->id)),
                'has_active_koreksi' => $koreksiAktif->has($invoice->id),
                'items'              => $invoice->items->map(fn($it) => [
                    'kode_barang'      => $it->kode_barang,
                    'nama_barang'      => $it->nama_barang,
                    'qty'              => (float) $it->qty,
                    'satuan'           => $it->satuan,
                    'harga_satuan'     => (float) $it->harga_satuan,
                    'no_invoice_resto' => $it->no_invoice_resto,
                    'kode_resto'       => $it->kode_resto,
                    'nama_resto'       => $it->nama_resto,
                ])->all(),
                'header' => [
                    'tanggal_jatuh_tempo' => $this->dateOrNull($invoice->tanggal_jatuh_tempo),
                    'no_surat_jalan'      => $invoice->no_surat_jalan,
                    'keterangan'          => $invoice->keterangan,
                ],
            ];
        }

        return $snapshots;
    }

    // ══════════════════════════════════════════════════════════════
    //  FASE 3 — Proses Data Aman
    // ══════════════════════════════════════════════════════════════

    /** Tandai batch siap diproses & hitung berapa grup yang akan ditulis. */
    public function beginApply(InvoiceImportBatch $batch): int
    {
        $total = InvoiceImportGroup::where('batch_id', $batch->id)
            ->whereIn('classification', InvoiceImportGroup::SAFE_CLASSIFICATIONS)
            ->whereNull('apply_status')
            ->count();

        $batch->update([
            'status'            => 'processing',
            'phase'             => 'applying',
            'applied_total'     => $total,
            'applied_processed' => 0,
            'applied_at'        => now(),
        ]);

        return $total;
    }

    /**
     * Proses satu chunk grup aman + catat hasilnya ke riwayat perubahan.
     * Mengembalikan true jika masih ada sisa.
     *
     * REJECTED / UNCHANGED tidak pernah masuk ke sini (lihat SAFE_CLASSIFICATIONS).
     */
    public function applySafeChunk(InvoiceImportBatch $batch, int $limit = self::APPLY_CHUNK): bool
    {
        $lockedEbMap = $this->groupProcessor->buildLockedEbMap();

        $groups = InvoiceImportGroup::where('batch_id', $batch->id)
            ->whereIn('classification', InvoiceImportGroup::SAFE_CLASSIFICATIONS)
            ->whereNull('apply_status')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($groups->isEmpty()) {
            return false;
        }

        $existingInvoiceMap = $this->buildApplyExistingMap($groups);
        $klienMap = $this->buildApplyKlienMap($groups);
        $carryoverMap = $this->buildApplyCarryoverMap($groups);
        // Preload kandidat cascade-carryover (grup SAFE_UPDATE) sekali per chunk, lalu
        // hydrate $existingInvoiceMap supaya kedua map berbagi instance objek untuk baris
        // yang sama — WAJIB agar cascade antar-grup dalam 1 chunk saling melihat mutasi
        // terbaru alih-alih objek stale. Lihat docblock buildApplyCascadeCandidatesMap()
        // dan hydrateExistingInvoiceMapForCascade().
        $cascadeCandidatesMap = $this->buildApplyCascadeCandidatesMap($groups);
        $existingInvoiceMap = $this->hydrateExistingInvoiceMapForCascade($existingInvoiceMap, $cascadeCandidatesMap);
        // Preload rows semua grup dalam chunk ini sekali (mirror pola classify()'s
        // $rowsByGroup) — bukan 1 query rows() per grup di dalam applyGroup().
        $rowsByGroup = InvoiceImportRow::whereIn('group_id', $groups->pluck('id'))
            ->orderBy('row_number')
            ->get()
            ->groupBy('group_id');
        $errors = $batch->errors ?? [];

        $groupUpdates  = [];
        $counterDeltas = ['applied_inserted' => 0, 'applied_updated' => 0, 'applied_skipped' => 0, 'applied_failed' => 0];
        $now = now();

        // Buffer riwayat perubahan HANYA untuk chunk ini, di-flush sebelum method
        // ini selesai. Sengaja tidak diakumulasi lintas chunk: tiap chunk berjalan
        // di ProcessInvoiceImportChunkJob yang terpisah, jadi buffer in-memory tidak
        // mungkin bertahan antar-chunk.
        $changeLog = [];

        // Batching: sinkronisasi Ending Balance per invoice (via InvoiceObserver)
        // ditunda dan di-dedup, lalu di-flush sekali per klien+periode di akhir
        // chunk ini alih-alih recompute penuh (+cascade 6 bulan) berulang kali.
        EndingBalanceSyncBatcher::run(function () use (
            $batch, $groups, $lockedEbMap, $existingInvoiceMap, $klienMap, $carryoverMap, $cascadeCandidatesMap, $rowsByGroup,
            &$errors, &$groupUpdates, &$counterDeltas, &$changeLog, $now,
        ) {
            foreach ($groups as $group) {
                try {
                    $rows = $rowsByGroup->get($group->id, collect());
                    $result = $this->applyGroup($group, $lockedEbMap, $existingInvoiceMap, $klienMap, $rows, $carryoverMap, $cascadeCandidatesMap);
                } catch (\Throwable $e) {
                    Log::error('InvoiceImportService: gagal memproses grup', ['group_id' => $group->id, 'error' => $e->getMessage()]);
                    $result = ['status' => 'FAILED', 'message' => 'Error tak terduga: ' . $e->getMessage(), 'invoice_id' => null, 'no_invoice' => null];
                }

                $groupUpdates[] = [
                    'id'            => $group->id,
                    // batch_id/group_key NOT NULL tanpa default — MySQL INSERT ... ON DUPLICATE
                    // KEY UPDATE tetap memvalidasi row lengkap untuk cabang INSERT meski baris ini
                    // sebenarnya match id existing (mirror pola classify() di atas).
                    'batch_id'      => $group->batch_id,
                    'group_key'     => $group->group_key,
                    'apply_status'  => $result['status'],
                    'apply_message' => $result['message'],
                    'invoice_id'    => $result['invoice_id'] ?? $group->invoice_id,
                    'no_invoice'    => $result['no_invoice'] ?? $group->no_invoice,
                    'updated_at'    => $now,
                ];

                if (in_array($result['status'], ['SKIPPED', 'FAILED'], true)) {
                    $errors[] = ['row' => $group->first_line, 'message' => $result['message']];
                }

                // Riwayat perubahan. SKIPPED sengaja dilewat: itu guard processGroup()
                // (invoice sudah LUNAS / periode terkunci) yang lolos ke fase apply karena
                // race dengan aktivitas lain — bukan error, dan pola dua tab import lainnya
                // memang tidak mencatat baris yang dilewati.
                if ($result['status'] === 'APPLIED') {
                    $this->recordChange(
                        $changeLog,
                        $batch->id,
                        (int) $group->first_line,
                        $group->classification === 'NEW_INVOICE' ? 'ditambahkan' : 'diperbarui',
                        $this->changeLogSebelum($group),
                        $this->changeLogBaru($group, $result['no_invoice'] ?? null),
                    );
                } elseif ($result['status'] === 'FAILED') {
                    $this->recordChange(
                        $changeLog,
                        $batch->id,
                        (int) $group->first_line,
                        'gagal',
                        $this->changeLogSebelum($group),
                        $this->changeLogBaru($group),
                        $result['message'],
                    );
                }

                $counterDeltas[match ($result['status']) {
                    'APPLIED' => $group->classification === 'NEW_INVOICE' ? 'applied_inserted' : 'applied_updated',
                    'SKIPPED' => 'applied_skipped',
                    default   => 'applied_failed',
                }]++;
            }
        });

        // Bulk upsert hasil apply per grup — 1 query untuk semua grup di chunk ini, bukan
        // $group->update() + 2x $batch->increment() per grup (mirror pola classify()).
        if (!empty($groupUpdates)) {
            InvoiceImportGroup::upsert(
                $groupUpdates,
                ['id'],
                ['apply_status', 'apply_message', 'invoice_id', 'no_invoice', 'updated_at'],
            );
        }

        $batch->update([
            'errors'            => $errors,
            'applied_processed' => $batch->applied_processed + count($groupUpdates),
            'applied_inserted'  => $batch->applied_inserted + $counterDeltas['applied_inserted'],
            'applied_updated'   => $batch->applied_updated + $counterDeltas['applied_updated'],
            'applied_skipped'   => $batch->applied_skipped + $counterDeltas['applied_skipped'],
            'applied_failed'    => $batch->applied_failed + $counterDeltas['applied_failed'],
        ]);

        // Kegagalan menulis riwayat perubahan TIDAK boleh menggagalkan invoice yang
        // sudah berhasil ditulis di atas — cukup di-log (mirror OpeningBalanceImportService).
        try {
            $this->flushChangeLog($changeLog);
        } catch (\Throwable $e) {
            Log::warning('Gagal menulis riwayat perubahan Import Master Invoice', [
                'batch_id' => $batch->id,
                'error'    => $e->getMessage(),
            ]);
        }

        return InvoiceImportGroup::where('batch_id', $batch->id)
            ->whereIn('classification', InvoiceImportGroup::SAFE_CLASSIFICATIONS)
            ->whereNull('apply_status')
            ->exists();
    }

    /** @return array<string, Invoice> key: "klienArId|Y-m-d" */
    private function buildApplyExistingMap($groups): array
    {
        $klienIds = $groups->pluck('klien_ar_id')->filter()->unique()->values()->all();
        $tanggals = $groups->map(fn($g) => $this->dateOrNull($g->tanggal_invoice))->filter()->unique()->values()->all();

        if (empty($klienIds) || empty($tanggals)) {
            return [];
        }

        return Invoice::whereIn('klien_ar_id', $klienIds)
            ->whereIn('tanggal_invoice', $tanggals)
            ->where('is_opening_balance', false)
            ->get()
            ->keyBy(fn($inv) => $inv->klien_ar_id . '|' . Carbon::parse($inv->tanggal_invoice)->toDateString())
            ->all();
    }

    /**
     * Preload klien_ar_id => KlienAr (with perusahaan) untuk createInvoice() (grup NEW_INVOICE)
     * — hindari KlienAr::with('perusahaan')->find() per grup di InvoiceGroupProcessor.
     *
     * @return array<int, KlienAr>
     */
    private function buildApplyKlienMap($groups): array
    {
        $klienIds = $groups->pluck('klien_ar_id')->filter()->unique()->values()->all();

        if (empty($klienIds)) {
            return [];
        }

        return KlienAr::with('perusahaan')->whereIn('id', $klienIds)->get()->keyBy('id')->all();
    }

    /**
     * Preload klien_ar_id => Collection<Invoice> (is_opening_balance=false, status
     * TERKIRIM/SEBAGIAN) untuk InvoiceService::getMonthlyCarryover() dipanggil dari
     * createInvoice() (grup NEW_INVOICE) — hindari 1 query SUM per grup.
     *
     * @return array<int, \Illuminate\Support\Collection>
     */
    private function buildApplyCarryoverMap($groups): array
    {
        $klienIds = $groups
            ->where('classification', 'NEW_INVOICE')
            ->pluck('klien_ar_id')->filter()->unique()->values()->all();

        if (empty($klienIds)) {
            return [];
        }

        return Invoice::whereIn('klien_ar_id', $klienIds)
            ->where('is_opening_balance', false)
            ->whereIn('status', ['TERKIRIM', 'SEBAGIAN'])
            ->get(['klien_ar_id', 'tanggal_invoice', 'subtotal', 'total_pembayaran', 'total_penyesuaian'])
            ->groupBy('klien_ar_id')
            ->all();
    }

    /**
     * Preload klien_ar_id => Collection<Invoice> (SEMUA invoice klien tsb — status apa pun,
     * termasuk Opening Balance, TIDAK difilter seperti buildApplyCarryoverMap() yang untuk
     * tujuan lain) untuk klien yang tersentuh grup SAFE_UPDATE di chunk ini. Dipakai
     * InvoiceService::recalculate() → cascadeCarryoverToNext() supaya cascade jalan
     * in-memory, bukan 1 query "next invoice" + query sum per hop.
     *
     * Replika PERSIS filter/order query live di cascadeCarryoverToNext(): tanggal_invoice
     * >= monthStart, orderBy tanggal_invoice, orderBy id. $minMonthStart pakai bulan
     * paling awal di antara SEMUA grup SAFE_UPDATE chunk ini (1 lower bound global) —
     * aman meski tanggal invoice per grup berbeda-beda karena cascadeCarryoverToNext()/
     * sumOwnSisaFromCandidates() re-derive $monthStart dari invoice yang di-cascade
     * sendiri; over-fetch (kandidat dari bulan lebih awal dari yang perlu) tidak masalah,
     * under-fetch yang berbahaya.
     *
     * @return array<int, \Illuminate\Support\Collection>
     */
    private function buildApplyCascadeCandidatesMap($groups): array
    {
        $safeUpdateGroups = $groups->where('classification', 'SAFE_UPDATE');
        $klienIds = $safeUpdateGroups->pluck('klien_ar_id')->filter()->unique()->values()->all();

        if (empty($klienIds)) {
            return [];
        }

        $minMonthStart = $safeUpdateGroups
            ->map(fn($g) => $this->dateOrNull($g->tanggal_invoice))
            ->filter()
            ->map(fn($d) => Carbon::parse($d)->startOfMonth())
            ->min();

        if (!$minMonthStart) {
            return [];
        }

        return Invoice::whereIn('klien_ar_id', $klienIds)
            ->where('tanggal_invoice', '>=', $minMonthStart->toDateString())
            ->orderBy('tanggal_invoice')
            ->orderBy('id')
            ->get()
            ->groupBy('klien_ar_id')
            ->all();
    }

    /**
     * Timpa entry $existingInvoiceMap yang overlap dengan objek yang sama dari
     * $cascadeCandidatesMap, supaya kedua map berbagi instance objek untuk baris DB yang
     * sama. WAJIB — tanpa ini, 1 chunk dengan >1 grup SAFE_UPDATE untuk klien yang sama
     * akan punya 2 objek PHP berbeda untuk baris invoice yang sama: cascade dari grup
     * pertama memutasi satu objek, grup berikutnya (klien sama) membaca objek LAIN yang
     * stale dari $existingInvoiceMap, menghasilkan sisa_tagihan/status salah secara diam-
     * diam (tanpa exception). cascadeCarryoverToNext() memang butuh membaca objek yang
     * sama yang dimutasi cascade sebelumnya — lihat docblock-nya di InvoiceService.
     *
     * @return array<string, Invoice>
     */
    private function hydrateExistingInvoiceMapForCascade(array $existingInvoiceMap, array $cascadeCandidatesMap): array
    {
        foreach ($cascadeCandidatesMap as $klienId => $collection) {
            foreach ($collection as $candidate) {
                $key = $klienId . '|' . Carbon::parse($candidate->tanggal_invoice)->toDateString();
                if (array_key_exists($key, $existingInvoiceMap)) {
                    $existingInvoiceMap[$key] = $candidate;
                }
            }
        }

        return $existingInvoiceMap;
    }

    /** @return array{status: string, message: ?string, invoice_id: ?int, no_invoice: ?string} */
    private function applyGroup(
        InvoiceImportGroup $group,
        array $lockedEbMap,
        array $existingInvoiceMap = [],
        array $klienMap = [],
        ?\Illuminate\Support\Collection $preloadedRows = null,
        array $carryoverMap = [],
        array $cascadeCandidatesMap = [],
    ): array {
        $items = $this->rowsToItems($preloadedRows ?? $group->rows()->orderBy('row_number')->get());

        if (empty($items)) {
            return ['status' => 'SKIPPED', 'message' => 'Tidak ada item untuk diproses.', 'invoice_id' => null, 'no_invoice' => null];
        }

        $headerData = [
            'klien_ar_id'         => $group->klien_ar_id,
            'tanggal_invoice'     => $this->dateOrNull($group->tanggal_invoice),
            'tanggal_jatuh_tempo' => $this->dateOrNull($group->tanggal_jatuh_tempo),
            'no_surat_jalan'      => $group->no_surat_jalan,
            'keterangan'          => $group->keterangan,
        ];

        // InvoiceGroupProcessor hanya mengganti item; untuk SAFE_UPDATE header juga
        // harus ikut diperbarui supaya reupload benar-benar menyamakan invoice
        // dengan file terbaru.
        if ($group->classification === 'SAFE_UPDATE' && $group->invoice_id && !empty($group->header_diff)) {
            Invoice::whereKey($group->invoice_id)->update([
                'tanggal_jatuh_tempo' => $headerData['tanggal_jatuh_tempo'],
                'no_surat_jalan'      => $headerData['no_surat_jalan'],
                'keterangan'          => $headerData['keterangan'],
                'updated_by'          => auth()->id(),
            ]);
        }

        $result = $this->groupProcessor->processGroup($group->tipe_invoice, $headerData, $items, $lockedEbMap, $existingInvoiceMap, $klienMap, $carryoverMap, $cascadeCandidatesMap);

        return match (true) {
            $result->isInserted(), $result->isUpdated() => [
                'status'     => 'APPLIED',
                'message'    => null,
                'invoice_id' => $result->invoice?->id,
                'no_invoice' => $result->invoice?->no_invoice,
            ],
            $result->isSkipped() => [
                'status'     => 'SKIPPED',
                'message'    => 'Dilewati: ' . $result->error,
                'invoice_id' => null,
                'no_invoice' => null,
            ],
            default => [
                'status'     => 'FAILED',
                'message'    => $result->error,
                'invoice_id' => null,
                'no_invoice' => null,
            ],
        };
    }

    /** Propagasi carryover untuk invoice yang baru dibuat, lalu tutup batch. */
    public function finalizeApply(InvoiceImportBatch $batch): void
    {
        $batch->update(['phase' => 'finalizing']);

        $newInvoiceIds = InvoiceImportGroup::where('batch_id', $batch->id)
            ->where('classification', 'NEW_INVOICE')
            ->where('apply_status', 'APPLIED')
            ->whereNotNull('invoice_id')
            ->pluck('invoice_id')
            ->all();

        if (!empty($newInvoiceIds)) {
            EndingBalanceSyncBatcher::run(function () use ($newInvoiceIds) {
                $this->groupProcessor->propagateCarryoverForNew(
                    Invoice::whereIn('id', $newInvoiceIds)->get()->all(),
                );
            });
        }

        $batch->refresh();

        $batch->update([
            'status'      => 'completed',
            'phase'       => 'completed',
            'finished_at' => now(),
            'message'     => sprintf(
                'Proses data aman selesai: %d dibuat, %d diperbarui, %d gagal. %d invoice dilewati (sudah lunas / periode terkunci).',
                $batch->applied_inserted,
                $batch->applied_updated,
                $batch->applied_failed,
                $batch->cnt_rejected + $batch->applied_skipped,
            ),
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    //  Riwayat perubahan (tb_invoice_import_change_log)
    // ══════════════════════════════════════════════════════════════

    /**
     * Buffer 1 entri riwayat perubahan — MURNI di memori, TIDAK ditulis ke DB di sini
     * (lihat flushChangeLog()). Mirror OpeningBalanceImportService::recordChange().
     *
     * HANYA 'ditambahkan' | 'diperbarui' | 'gagal' yang boleh masuk, mengikuti pola dua
     * tab import lainnya. Grup REJECTED (sudah lunas / periode terkunci), UNCHANGED, dan
     * SKIPPED sengaja TIDAK dicatat — jumlahnya sudah terwakili counter pada batch.
     */
    private function recordChange(
        array   &$buffer,
        string  $batchId,
        int     $rowNumber,
        string  $changeType,
        ?array  $dataSebelum,
        ?array  $dataBaru,
        ?string $message = null,
    ): void {
        $searchParts = array_filter(
            array_merge(array_values($dataSebelum ?? []), array_values($dataBaru ?? []), [$message]),
            static fn($v) => is_string($v) && $v !== '',
        );

        $now = now();

        $buffer[] = [
            'batch_id'     => $batchId,
            'row_number'   => $rowNumber,
            'change_type'  => $changeType,
            'data_sebelum' => $dataSebelum !== null ? json_encode($dataSebelum) : null,
            'data_baru'    => $dataBaru !== null ? json_encode($dataBaru) : null,
            'message'      => $message,
            'search_text'  => mb_substr(implode(' ', array_unique($searchParts)), 0, 500),
            'created_at'   => $now,
            'updated_at'   => $now,
        ];
    }

    /** Bulk insert isi buffer riwayat perubahan, dipecah per CHANGE_LOG_FLUSH_SIZE baris per statement. */
    private function flushChangeLog(array $buffer): void
    {
        foreach (array_chunk($buffer, self::CHANGE_LOG_FLUSH_SIZE) as $chunk) {
            DB::table('tb_invoice_import_change_log')->insert($chunk);
        }
    }

    /**
     * Isi kolom data_sebelum: kondisi invoice existing SEBELUM ditimpa isi file.
     * Hanya relevan untuk SAFE_UPDATE — NEW_INVOICE belum punya "sebelum".
     */
    private function changeLogSebelum(InvoiceImportGroup $group): ?array
    {
        if ($group->classification !== 'SAFE_UPDATE') {
            return null;
        }

        $diff = $group->header_diff ?? [];

        // array_key_exists (bukan ??) disengaja: kalau field ADA di header_diff tapi nilai
        // lamanya null, itu memang "dulu kosong" — jangan sampai jatuh ke nilai file baru.
        return $this->pruneEmpty([
            'no_invoice'          => $group->no_invoice,
            'nama_klien'          => $group->nama_klien,
            'kode_resto'          => $group->kode_resto,
            'tanggal_invoice'     => $this->dateOrNull($group->tanggal_invoice),
            'tanggal_jatuh_tempo' => array_key_exists('tanggal_jatuh_tempo', $diff)
                ? $diff['tanggal_jatuh_tempo']['lama']
                : $this->dateOrNull($group->tanggal_jatuh_tempo),
            'no_surat_jalan'      => array_key_exists('no_surat_jalan', $diff)
                ? $diff['no_surat_jalan']['lama']
                : $group->no_surat_jalan,
            'total'               => $this->rupiah((float) $group->total_lama),
        ]);
    }

    /** Isi kolom data_baru: kondisi invoice SESUDAH ditulis dari file. */
    private function changeLogBaru(InvoiceImportGroup $group, ?string $noInvoice = null): array
    {
        return $this->pruneEmpty([
            'no_invoice'          => $noInvoice ?? $group->no_invoice,
            'nama_klien'          => $group->nama_klien,
            'kode_resto'          => $group->kode_resto,
            'tanggal_invoice'     => $this->dateOrNull($group->tanggal_invoice),
            'tanggal_jatuh_tempo' => $this->dateOrNull($group->tanggal_jatuh_tempo),
            'no_surat_jalan'      => $group->no_surat_jalan,
            'total'               => $this->rupiah((float) $group->total_baru),
            'jumlah_item'         => (string) $group->item_count,
        ]);
    }

    /**
     * Buang key bernilai null/'' supaya "Lihat detail" di FE tidak penuh baris kosong.
     * Semua value sengaja string: search_text hanya mengindeks value bertipe string.
     */
    private function pruneEmpty(array $data): array
    {
        return array_filter($data, static fn($v) => $v !== null && $v !== '');
    }

    private function rupiah(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }

    // ══════════════════════════════════════════════════════════════
    //  Helper umum
    // ══════════════════════════════════════════════════════════════

    /** @return array<int, array> item siap dipakai InvoiceGroupProcessor */
    private function rowsToItems($rows): array
    {
        return $rows->map(fn(InvoiceImportRow $r) => [
            'barang_id'        => $r->barang_id,
            'kode_barang'      => $r->kode_barang,
            'nama_barang'      => $r->nama_barang,
            'qty'              => (float) $r->qty,
            'satuan'           => $r->satuan,
            'harga_satuan'     => (float) $r->harga_satuan,
            'no_invoice_resto' => $r->no_invoice_resto,
            'kode_resto'       => $r->kode_resto,
            'nama_resto'       => $r->nama_resto,
        ])->all();
    }

    /**
     * $rejectedRows dihitung terpisah dari cnt_rejected: baris yang gagal validasi
     * SEBELUM grouping (klien tidak ketemu, tipe_invoice tidak cocok MASTER DATA, dst)
     * tidak pernah menjadi grup, jadi kalau tidak disebut di sini pesannya akan
     * berbunyi "0 ditolak" padahal ada baris yang tidak terpakai.
     */
    private function buildClassificationMessage(array $c, int $rejectedRows = 0): string
    {
        $message = sprintf(
            'Klasifikasi selesai: %d baru, %d update aman, %d tanpa perubahan, %d dilewati (sudah lunas / periode terkunci).',
            $c['cnt_new'], $c['cnt_safe_update'], $c['cnt_unchanged'], $c['cnt_rejected'],
        );

        return $rejectedRows > 0
            ? $message . " {$rejectedRows} baris file ditolak saat pembacaan — lihat daftar error."
            : $message;
    }

    /** Pesan untuk batch yang dibatalkan otomatis karena tidak ada invoice yang perlu ditulis. */
    private function buildAutoCancelMessage(array $c): string
    {
        return sprintf(
            'Tidak ada perubahan untuk ditulis (%d tanpa perubahan, %d dilewati) — data sama dengan yang sudah tersimpan. '
            . 'Import dibatalkan otomatis, silakan unggah file revisi jika ada perubahan.',
            $c['cnt_unchanged'], $c['cnt_rejected'],
        );
    }

    private function nullIfBlank(mixed $val): ?string
    {
        if ($val === null) return null;
        $s = trim((string) $val);

        return $s === '' ? null : $s;
    }

    private function dateOrNull(mixed $val): ?string
    {
        if (!$val) return null;
        if ($val instanceof \DateTimeInterface) return $val->format('Y-m-d');

        return substr((string) $val, 0, 10);
    }

    // ── Lookup master (dipindahkan dari MasterImportService) ──────────────

    /**
     * @return array{0: array<string,KlienAr>, 1: array<string,KlienAr>, 2: array<string,KlienAr>, 3: array<string,int>}
     *   [0] namaMap, [1] restoMap by kode_resto, [2] restoNameMap, [3] restoNameCount.
     * Hanya Client AR aktif — Client AR yang sudah dinonaktifkan (mis. outlet pindah
     * segmen PT/RESTO) tidak boleh dipakai untuk invoice baru.
     */
    private function buildKlienMaps(): array
    {
        $namaMap = $restoMap = $restoNameMap = $restoNameCount = [];

        $klienList = KlienAr::with('resto:id,kode_resto')
            ->where('status', true)
            ->get(['id', 'nama_klien', 'tipe_klien', 'resto_id', 'perusahaan_id', 'karyawan_ar_id']);

        foreach ($klienList as $klien) {
            if ($klien->nama_klien === null) continue;
            $namaKey = strtolower($klien->nama_klien);

            $namaMap[$namaKey] ??= $klien;

            if ($klien->tipe_klien === 'RESTO') {
                $restoNameCount[$namaKey] = ($restoNameCount[$namaKey] ?? 0) + 1;
                $restoNameMap[$namaKey] ??= $klien;

                $kodeResto = $klien->resto?->kode_resto;
                if ($kodeResto !== null && $kodeResto !== '') {
                    $restoMap[strtoupper($kodeResto)] ??= $klien;
                }
            }
        }

        return [$namaMap, $restoMap, $restoNameMap, $restoNameCount];
    }

    /**
     * Peta acuan MASTER DATA per kode_resto: segmen yang SEDANG AKTIF untuk tiap outlet.
     * @return array<string, array{tipe_klien: string, nama_klien: string, klien_id: int}>
     */
    private function buildRestoMasterMap(): array
    {
        $map    = [];
        $restos = Resto::whereNotNull('kode_resto')
            ->where('kode_resto', '!=', '')
            ->get(['id', 'kode_resto', 'perusahaan_id']);

        if ($restos->isEmpty()) return $map;

        $restoKlienByRestoId = [];
        foreach (
            KlienAr::where('tipe_klien', 'RESTO')->where('status', true)
                ->whereIn('resto_id', $restos->pluck('id'))
                ->get(['id', 'resto_id', 'nama_klien']) as $klien
        ) {
            $restoKlienByRestoId[$klien->resto_id] ??= $klien;
        }

        $ptKlienByPerusahaanId = [];
        foreach (
            KlienAr::where('tipe_klien', 'PT')->where('status', true)
                ->whereIn('perusahaan_id', $restos->pluck('perusahaan_id')->filter()->unique())
                ->get(['id', 'perusahaan_id', 'nama_klien']) as $klien
        ) {
            $ptKlienByPerusahaanId[$klien->perusahaan_id] ??= $klien;
        }

        foreach ($restos as $resto) {
            $kodeKey = strtoupper($resto->kode_resto);

            if (isset($restoKlienByRestoId[$resto->id])) {
                $klien         = $restoKlienByRestoId[$resto->id];
                $map[$kodeKey] = ['tipe_klien' => 'RESTO', 'nama_klien' => $klien->nama_klien, 'klien_id' => $klien->id];
                continue;
            }

            if ($resto->perusahaan_id && isset($ptKlienByPerusahaanId[$resto->perusahaan_id])) {
                $klien         = $ptKlienByPerusahaanId[$resto->perusahaan_id];
                $map[$kodeKey] = ['tipe_klien' => 'PT', 'nama_klien' => $klien->nama_klien, 'klien_id' => $klien->id];
            }
        }

        return $map;
    }

    /**
     * Validasi 1 baris terhadap MASTER DATA. kode_resto adalah acuan utama —
     * tipe_invoice & nama_klien wajib konsisten dengan segmen outlet tersebut.
     */
    public function validateRowAgainstMasterData(string $tipeInvoice, string $namaKlien, ?string $kodeResto, array $restoMasterMap): ?string
    {
        if (!$kodeResto) {
            return 'kode_resto wajib diisi.';
        }

        $entry = $restoMasterMap[strtoupper($kodeResto)] ?? null;
        if (!$entry) {
            return "kode_resto '{$kodeResto}' tidak ditemukan di MASTER DATA atau belum memiliki Client AR aktif.";
        }

        $expected = $entry['tipe_klien'] === 'PT' ? 'B2B' : 'B2C';
        if ($tipeInvoice !== $expected) {
            return "kode_resto '{$kodeResto}' terdaftar sebagai {$entry['tipe_klien']} di MASTER DATA — tipe_invoice seharusnya '{$expected}', bukan '{$tipeInvoice}'.";
        }

        // nama_klien kosong (B2C dikirim tanpa isi manual) -> percaya kode_resto saja,
        // lewati pengecekan kecocokan nama. Kalau diisi, tetap harus cocok (melindungi
        // dari typo/salah klien).
        if ($namaKlien !== '' && strtolower($namaKlien) !== strtolower($entry['nama_klien'])) {
            return "nama_klien '{$namaKlien}' tidak sesuai MASTER DATA untuk kode_resto '{$kodeResto}' (seharusnya '{$entry['nama_klien']}').";
        }

        return null;
    }

    /**
     * B2B resolve via nama_klien (konsolidasi multi-outlet). B2C resolve via
     * kode_resto — tanpa fallback ke nama supaya salah ketik tidak nyasar ke outlet lain.
     *
     * @return array{0: ?KlienAr, 1: ?string}
     */
    private function resolveKlienForRow(
        string $tipeInvoice,
        string $namaKlien,
        ?string $kodeResto,
        array $namaMap,
        array $restoMap,
        array $restoNameMap,
        array $restoNameCount,
    ): array {
        $namaKey = strtolower($namaKlien);

        if ($tipeInvoice === 'B2B') {
            $klien = $namaMap[$namaKey] ?? null;

            return $klien ? [$klien, null] : [null, "Klien '{$namaKlien}' tidak ditemukan di sistem."];
        }

        if ($kodeResto) {
            $klien = $restoMap[strtoupper($kodeResto)] ?? null;

            return $klien
                ? [$klien, null]
                : [null, "kode_resto '{$kodeResto}' tidak ditemukan atau belum terhubung ke Client AR tipe RESTO."];
        }

        $count = $restoNameCount[$namaKey] ?? 0;
        if ($count === 0) return [null, "Klien '{$namaKlien}' tidak ditemukan di sistem."];
        if ($count > 1)   return [null, "Klien '{$namaKlien}' memiliki {$count} outlet berbeda. Isi kolom kode_resto pada baris ini."];

        return [$restoNameMap[$namaKey], null];
    }

    /** @return array<string,int> upper(kode_barang) => id */
    private function buildBarangMap(): array
    {
        $map = [];
        foreach (Barang::all(['id', 'kode_barang']) as $barang) {
            if ($barang->kode_barang !== null) {
                $map[strtoupper($barang->kode_barang)] ??= $barang->id;
            }
        }

        return $map;
    }

    // ── Parsing CSV (native PHP — TANPA PhpSpreadsheet) ────────────────────

    /** Buka file CSV, lewati BOM UTF-8 di awal file kalau ada. @return resource */
    private function openCsvHandle(string $filePath)
    {
        $handle = fopen($filePath, 'r');
        $bom    = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        return $handle;
    }

    /**
     * Deteksi delimiter (';' atau ',') dari beberapa baris pertama file — supaya baik
     * file dari template kita sendiri (';', mengikuti list separator Excel locale
     * Indonesia) maupun file dari sumber lain yang pakai koma standar tetap terbaca
     * benar, apa pun locale Excel yang dipakai user. Sampling beberapa baris (bukan
     * cuma baris pertama) karena baris pertama sering berupa judul/instruksi teks
     * biasa tanpa delimiter sama sekali.
     */
    private function detectCsvDelimiter(string $filePath, int $sampleLines = 20): string
    {
        $handle = $this->openCsvHandle($filePath);
        $sample = '';
        for ($i = 0; $i < $sampleLines; $i++) {
            $line = fgets($handle);
            if ($line === false) break;
            $sample .= $line;
        }
        fclose($handle);

        return substr_count($sample, ';') > substr_count($sample, ',') ? ';' : ',';
    }

    /**
     * Cari baris header "nama_klien" (maks $previewLines baris pertama), lalu lanjut
     * membaca sampai EOF untuk menghitung total baris data — TANPA menyimpan isinya
     * (cuma counter, memori tetap O(1)). File dibaca sekali penuh untuk ini; parsing
     * sesungguhnya membuka handle baru lewat chunkCsvRows().
     *
     * @return array{headerLineIdx: int, totalRows: int, delimiter: string}
     */
    private function scanCsvHeaderAndCount(string $filePath, int $previewLines = 100): array
    {
        $delimiter     = $this->detectCsvDelimiter($filePath);
        $handle        = $this->openCsvHandle($filePath);
        $headerLineIdx = null;
        $lineIdx       = 0;
        $totalRows     = 0;

        while (($row = fgetcsv($handle, null, $delimiter)) !== false) {
            if ($headerLineIdx === null) {
                $first = $this->normalizeHeaderName(trim((string) ($row[0] ?? '')));
                if ($first === 'nama_klien') {
                    $headerLineIdx = $lineIdx;
                } elseif ($lineIdx >= $previewLines) {
                    fclose($handle);
                    throw new \RuntimeException('Header "nama_klien" tidak ditemukan di file CSV.');
                }
            } else {
                $totalRows++;
            }
            $lineIdx++;
        }
        fclose($handle);

        if ($headerLineIdx === null) {
            throw new \RuntimeException('Header "nama_klien" tidak ditemukan di file CSV.');
        }

        return ['headerLineIdx' => $headerLineIdx, 'totalRows' => $totalRows, 'delimiter' => $delimiter];
    }

    /**
     * Buka handle BARU (fresh), lewati $skipLines baris pertama (instruksi + baris
     * header itu sendiri), lalu baca sisanya satu baris sekaligus via fgetcsv() dan
     * panggil $onRow(array $cells) per baris. Tidak ada konsep chunk/removeRow —
     * fgetcsv() secara alami cuma menyimpan 1 baris di memori tiap iterasi, jadi ini
     * malah lebih ringan dari pendekatan chunked-XLSX yang dipakai sebelumnya.
     */
    private function chunkCsvRows(string $filePath, int $skipLines, string $delimiter, callable $onRow): void
    {
        $handle = $this->openCsvHandle($filePath);

        for ($i = 0; $i < $skipLines; $i++) {
            if (fgetcsv($handle, null, $delimiter) === false) {
                fclose($handle);

                return;
            }
        }

        while (($row = fgetcsv($handle, null, $delimiter)) !== false) {
            $onRow(array_slice($row, 0, self::MAX_COLS));
        }

        fclose($handle);
    }

    // ── Parsing XLSX (PhpSpreadsheet, pola sama seperti MasterImportService) ──

    private function findSheetIndex(Spreadsheet $spreadsheet, string $name): ?int
    {
        foreach ($spreadsheet->getSheetNames() as $i => $sheetName) {
            if (strtolower(trim($sheetName)) === strtolower($name)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Preview-scan (maks $previewRows baris pertama) mencari baris header "nama_klien"
     * pada sheet MASTER INVOICE — pola sama seperti MasterImportService::detectMasterHeaderStart().
     *
     * @return array{found: bool, dataStart: int, highestColumn: string, highestRow: int}
     */
    private function detectInvoiceHeaderStart(Worksheet $sheet, int $previewRows = 100): array
    {
        $highestRow   = $sheet->getHighestRow();
        $maxColLetter = Coordinate::stringFromColumnIndex(self::MAX_COLS);
        $previewEnd   = min($previewRows, $highestRow);

        $preview = $sheet->rangeToArray("A1:{$maxColLetter}{$previewEnd}", null, true, false, false);

        foreach ($preview as $idx => $cells) {
            $firstCell = trim($this->xlsxRawValueToString($cells[0] ?? ''));
            if ($this->normalizeHeaderName($firstCell) === 'nama_klien') {
                return [
                    'found'         => true,
                    'dataStart'     => $idx + 2, // 0-based idx -> baris header 1-based -> baris data pertama
                    'highestColumn' => $maxColLetter,
                    'highestRow'    => $highestRow,
                ];
            }
        }

        return ['found' => false, 'dataStart' => 0, 'highestColumn' => $maxColLetter, 'highestRow' => $highestRow];
    }

    /**
     * Baca baris data mulai $dataStart per $chunkSize baris via rangeToArray(), panggil
     * $onRow(array $cells, int $excelRowNumber) per baris, lalu lepas baris yang sudah
     * diproses lewat removeRow() supaya memori tidak menumpuk (pola sama seperti
     * MasterImportService::chunkMasterRows()). $excelRowNumber dihitung terpisah dari
     * pergeseran removeRow() supaya tetap merujuk ke nomor baris asli di file Excel.
     */
    private function chunkXlsxRows(
        Worksheet $sheet, int $dataStart, string $highestColumn, int $chunkSize, callable $onRow,
    ): void {
        $rowNumber = $dataStart;

        while (($currentHighest = $sheet->getHighestRow()) >= $dataStart) {
            $end   = min($dataStart + $chunkSize - 1, $currentHighest);
            $count = $end - $dataStart + 1;
            $rows  = $sheet->rangeToArray("A{$dataStart}:{$highestColumn}{$end}", null, true, false, false);

            foreach ($rows as $rawRow) {
                $onRow(array_map(fn($c) => $this->xlsxRawValueToString($c), $rawRow), $rowNumber);
                $rowNumber++;
            }

            $sheet->removeRow($dataStart, $count);
        }
    }

    /**
     * Konversi nilai mentah dari rangeToArray() (setReadDataOnly(true) tidak memuat style,
     * jadi info format tanggal cell tidak tersedia) — importDate() sudah punya fallback
     * numerik sendiri untuk cell bertipe serial number.
     */
    private function xlsxRawValueToString(mixed $value): string
    {
        if ($value === null)  return '';
        if (is_bool($value))  return $value ? '1' : '0';
        if (is_int($value))   return (string) $value;
        if (is_float($value)) {
            return fmod($value, 1.0) === 0.0 ? sprintf('%.0f', $value) : (string) $value;
        }

        return trim((string) $value);
    }

    private function normalizeHeaderName(string $raw): string
    {
        return trim(preg_replace('/\(\s*\*\s*\)\s*$/', '', strtolower(trim($raw))));
    }

    /**
     * Deteksi sel yang berisi kode error kalkulasi Excel (rumus gagal, mis. =A1/0 → "#DIV/0!")
     * di mana pun dalam baris, dan kembalikan pesan siap-tampil (bahasa awam, sebut baris & kolom
     * persis sesuai file fisik) atau null kalau baris bersih. $row harus 0-based dari kolom A
     * (hasil rangeToArray("A...")) supaya nomor kolom yang dilaporkan akurat.
     */
    private function detectFormulaError(array $row, string $sheetName, int $lineNumber): ?string
    {
        foreach ($row as $idx => $val) {
            $s = trim((string) $val);
            if (isset(self::EXCEL_FORMULA_ERRORS[$s])) {
                $col         = Coordinate::stringFromColumnIndex($idx + 1);
                $explanation = self::EXCEL_FORMULA_ERRORS[$s];

                return "Baris {$lineNumber}: sheet '{$sheetName}' kolom {$col} berisi rumus Excel yang error "
                    . "(kode {$s}: {$explanation}). Buka file Excel Anda, ganti sel tersebut dengan angka/teks "
                    . "biasa (bukan rumus), lalu upload ulang.";
            }
        }

        return null;
    }

    private function importValue(mixed $val): ?string
    {
        $s = trim((string) $val);

        return ($s === '' || $s === '-') ? null : $s;
    }

    private function importDate(mixed $val): ?string
    {
        return ImportDateParser::parse($val);
    }

    private function importNum(mixed $val): float
    {
        $s = str_replace(['.', ','], ['', '.'], trim((string) $val));

        return is_numeric($s) ? (float) $s : 0.0;
    }
}
