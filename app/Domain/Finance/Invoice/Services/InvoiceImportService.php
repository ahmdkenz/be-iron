<?php

namespace App\Domain\Finance\Invoice\Services;

use App\Domain\Finance\Invoice\Jobs\UploadInvoiceToGDriveJob;
use App\Models\Barang;
use App\Models\Invoice;
use App\Models\InvoiceImportBatch;
use App\Models\KlienAr;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Memproses import invoice (B2B / B2C) dari file CSV/XLSX di dalam queue job.
 *
 * Dioptimasi untuk ribuan baris:
 *  - Preload master klien & barang ke memori (hindari query per-baris)
 *  - Commit bertahap per CHUNK invoice (bukan satu transaksi raksasa)
 *  - Update progress ke InvoiceImportBatch agar bisa dipantau frontend
 *
 * Logika bisnis (validasi, dedup, carryover, dispatch PDF) dipertahankan
 * sama dengan implementasi sinkron sebelumnya di InvoiceController.
 */
class InvoiceImportService
{
    /** Jumlah baris per commit transaksi. */
    private const CHUNK = 100;

    public function __construct(private readonly InvoiceService $service) {}

    public function process(InvoiceImportBatch $batch): void
    {
        $disk = Storage::disk('local');
        if (!$batch->file_path || !$disk->exists($batch->file_path)) {
            throw new \RuntimeException("File import tidak ditemukan: {$batch->file_path}");
        }

        $fullPath = $disk->path($batch->file_path);
        $ext      = strtolower(pathinfo($batch->file_path, PATHINFO_EXTENSION));
        $isCsv    = in_array($ext, ['csv', 'txt']);

        if ($isCsv) {
            $rows1 = $this->parseCsv($fullPath);
            $rows2 = [];
        } else {
            $spreadsheet = IOFactory::load($fullPath);
            $rows1 = $this->parseXlsxSheet($spreadsheet->getSheet(0), 'no_urut');
            $rows2 = $spreadsheet->getSheetCount() > 1
                ? $this->parseXlsxSheet($spreadsheet->getSheet(1), 'no_urut_invoice')
                : [];
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }

        $batch->update([
            'status' => 'processing',
            'total'  => count($rows1) + count($rows2),
        ]);

        // Preload master untuk menghindari query per-baris.
        $klienMap  = $this->buildKlienMap();
        $barangMap = $this->buildBarangMap();

        if ($batch->type === 'b2b') {
            $this->importB2B($batch, $rows1, $rows2, $klienMap, $barangMap);
        } else {
            $this->importB2C($batch, $rows1, $rows2, $klienMap, $barangMap);
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Import B2C
    // ──────────────────────────────────────────────────────────────
    private function importB2C(InvoiceImportBatch $batch, array $rows1, array $rows2, array $klienMap, array $barangMap): void
    {
        $insertedCount  = 0;
        $skippedCount   = 0;
        $totalData      = 0;
        $errors         = [];
        $invoiceMapping = [];
        $skippedUruts   = [];
        $processed      = 0;

        // ── Pass 1: Invoice headers ─────────────────────────────────
        $lineNumber    = 0;
        $headerSkipped = false;
        $inChunk       = 0;

        DB::beginTransaction();
        try {
            foreach ($rows1 as $row) {
                if ($inChunk >= self::CHUNK) {
                    DB::commit();
                    $this->flushProgress($batch, $processed, $insertedCount, $skippedCount, $errors);
                    DB::beginTransaction();
                    $inChunk = 0;
                }
                $lineNumber++;
                $processed++;
                $inChunk++;

                $firstCell = trim((string) ($row[0] ?? ''));
                if (str_starts_with($firstCell, '#')) continue;
                if (!$headerSkipped) { $headerSkipped = true; continue; }
                if (str_starts_with($firstCell, '[CONTOH]')) continue;
                if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) continue;

                $totalData++;
                $noUrut       = $this->importStr($row[0] ?? '');   // A
                $namaKlien    = $this->importStr($row[1] ?? '');   // B
                $tanggal      = $this->importDate($row[2] ?? '');  // C: tanggal_invoice
                $tanggalKirim = $this->importDate($row[3] ?? '');  // D: tanggal_kirim_barang
                $jatuhTempo   = $this->importDate($row[4] ?? '');  // E: tanggal_jatuh_tempo
                $periodeAwal  = $this->importDate($row[5] ?? '');  // F: periode_awal
                $periodeAkhir = $this->importDate($row[6] ?? '');  // G: periode_akhir
                $noSuratJalan = $this->importStr($row[7] ?? '');   // H: no_surat_jalan
                $keterangan   = $this->importStr($row[8] ?? '');   // I: keterangan

                $validated = Validator::make(
                    [
                        'no_urut'         => $noUrut,
                        'nama_klien'      => $namaKlien,
                        'tanggal_invoice' => $tanggal,
                        'periode_awal'    => $periodeAwal,
                        'periode_akhir'   => $periodeAkhir,
                    ],
                    [
                        'no_urut'         => ['required'],
                        'nama_klien'      => ['required'],
                        'tanggal_invoice' => ['required', 'date'],
                        'periode_awal'    => ['required', 'date'],
                        'periode_akhir'   => ['required', 'date'],
                    ]
                );

                if ($validated->fails()) {
                    $errors[] = ['sheet' => 'Invoice', 'row' => $lineNumber, 'message' => implode(', ', $validated->errors()->all())];
                    continue;
                }

                $klien = $klienMap[$namaKlien] ?? null;
                if (!$klien) {
                    $errors[] = ['sheet' => 'Invoice', 'row' => $lineNumber, 'message' => "Klien '{$namaKlien}' tidak ditemukan di sistem"];
                    continue;
                }

                if (!$periodeAwal)  $periodeAwal  = Carbon::parse($tanggal)->startOfMonth()->format('Y-m-d');
                if (!$periodeAkhir) $periodeAkhir = Carbon::parse($tanggal)->endOfMonth()->format('Y-m-d');

                $existingInvoice = Invoice::where('klien_ar_id', $klien->id)
                    ->whereDate('tanggal_invoice', $tanggal)
                    ->where('is_opening_balance', false)
                    ->first();
                if ($existingInvoice) {
                    $skippedUruts[$noUrut] = true;
                    $skippedCount++;
                    continue;
                }

                $carryover = $this->service->getMonthlyCarryover($klien->id, $tanggal);
                $noInvoice = $this->service->generateNoInvoice($klien, $tanggal);

                try {
                    $invoice = Invoice::create([
                        'no_invoice'                 => $noInvoice,
                        'tanggal_invoice'            => $tanggal,
                        'tanggal_kirim_barang'       => $tanggalKirim ?: null,
                        'tanggal_jatuh_tempo'        => $jatuhTempo,
                        'periode_awal'               => $periodeAwal,
                        'periode_akhir'              => $periodeAkhir,
                        'klien_ar_id'                => $klien->id,
                        'resto_id'                   => $klien->resto_id,
                        'perusahaan_id'              => $klien->perusahaan_id,
                        'karyawan_id'                => $this->service->resolveInvoiceKaryawanId(auth()->user(), $klien),
                        'no_surat_jalan'             => $noSuratJalan,
                        'subtotal'                   => 0,
                        'tagihan_periode_sebelumnya' => $carryover,
                        'total_tagihan'              => $carryover,
                        'total_pembayaran'           => 0,
                        'sisa_tagihan'               => $carryover,
                        'status'                     => 'TERKIRIM',
                        'is_opening_balance'         => false,
                        'keterangan'                 => $keterangan,
                        'prepared_token'             => Str::uuid()->toString(),
                        'approved_token'             => Str::uuid()->toString(),
                        'created_by'                 => auth()->id(),
                    ]);

                    $invoiceMapping[$noUrut] = $invoice;
                    $insertedCount++;
                } catch (\Throwable $e) {
                    $errors[] = ['sheet' => 'Invoice', 'row' => $lineNumber, 'message' => 'Gagal menyimpan: ' . $e->getMessage()];
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();
            throw $e;
        }
        $this->flushProgress($batch, $processed, $insertedCount, $skippedCount, $errors);

        // ── Pass 2: Invoice items ───────────────────────────────────
        $invoicesWithItems = [];
        $lineNumber        = 0;
        $headerSkipped     = false;
        $inChunk           = 0;

        DB::beginTransaction();
        try {
            foreach ($rows2 as $row) {
                if ($inChunk >= self::CHUNK) {
                    DB::commit();
                    $this->flushProgress($batch, $processed, $insertedCount, $skippedCount, $errors);
                    DB::beginTransaction();
                    $inChunk = 0;
                }
                $lineNumber++;
                $processed++;
                $inChunk++;

                $firstCell = trim((string) ($row[0] ?? ''));
                if (str_starts_with($firstCell, '#')) continue;
                if (!$headerSkipped) { $headerSkipped = true; continue; }
                if (str_starts_with($firstCell, '[CONTOH]')) continue;
                if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) continue;

                $noUrutInvoice = $this->importStr($row[0] ?? '');
                $kodeBarang    = $this->importStr($row[1] ?? '');
                $namaBarang    = $this->importStr($row[2] ?? '');
                $qty           = $this->importNum($row[3] ?? '');
                $satuan        = $this->importStr($row[4] ?? '');
                $hargaSatuan   = $this->importNum($row[5] ?? '');

                if (!$noUrutInvoice) {
                    $errors[] = ['sheet' => 'Item Invoice', 'row' => $lineNumber, 'message' => 'no_urut_invoice wajib diisi'];
                    continue;
                }
                if (!isset($invoiceMapping[$noUrutInvoice])) {
                    if (isset($skippedUruts[$noUrutInvoice])) continue;
                    $errors[] = ['sheet' => 'Item Invoice', 'row' => $lineNumber, 'message' => "no_urut_invoice '{$noUrutInvoice}' tidak ditemukan di Sheet Invoice"];
                    continue;
                }
                if (!$namaBarang) {
                    $errors[] = ['sheet' => 'Item Invoice', 'row' => $lineNumber, 'message' => 'nama_barang wajib diisi'];
                    continue;
                }
                if ($qty <= 0) {
                    $errors[] = ['sheet' => 'Item Invoice', 'row' => $lineNumber, 'message' => 'qty harus lebih dari 0'];
                    continue;
                }

                $barangId = null;
                if ($kodeBarang) {
                    if (isset($barangMap[$kodeBarang])) {
                        $barangId = $barangMap[$kodeBarang];
                    } else {
                        $errors[] = ['sheet' => 'Item Invoice', 'row' => $lineNumber, 'message' => "Kode barang '{$kodeBarang}' tidak ditemukan di master barang (item tetap disimpan tanpa referensi barang)"];
                    }
                }

                $invoice = $invoiceMapping[$noUrutInvoice];
                $invoice->items()->create([
                    'barang_id'    => $barangId,
                    'nama_barang'  => $namaBarang,
                    'qty'          => $qty,
                    'satuan'       => $satuan,
                    'harga_satuan' => $hargaSatuan,
                    'subtotal'     => $qty * $hargaSatuan,
                ]);
                $invoicesWithItems[$noUrutInvoice] = $invoice;
            }
            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();
            throw $e;
        }
        $this->flushProgress($batch, $processed, $insertedCount, $skippedCount, $errors);

        // ── Pass 3: Recompute subtotal per invoice ──────────────────
        $this->recomputeSubtotals($invoicesWithItems, fn($invoice) =>
            (float) $invoice->items()->sum('subtotal') + (float) $invoice->tagihan_periode_sebelumnya
        );

        // ── Pass 4 & 5: cascade carryover + dispatch PDF ────────────
        $this->finalizeBatch($batch, $invoiceMapping, $errors);

        $failed = max(0, $totalData - $insertedCount - $skippedCount);
        $batch->update([
            'status'     => 'completed',
            'processed'  => $batch->total,
            'total_data' => $totalData,
            'inserted'   => $insertedCount,
            'skipped'    => $skippedCount,
            'failed'     => $failed,
            'errors'     => $errors,
            'message'    => "Import selesai. {$insertedCount} ditambahkan, {$skippedCount} dilewati (sudah ada), {$failed} gagal.",
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Import B2B
    // ──────────────────────────────────────────────────────────────
    private function importB2B(InvoiceImportBatch $batch, array $rows1, array $rows2, array $klienMap, array $barangMap): void
    {
        $insertedCount     = 0;
        $skippedCount      = 0;
        $totalData         = 0;
        $errors            = [];
        $invoiceMapping    = [];
        $skippedUruts      = [];
        $invoicesWithItems = [];
        $processed         = 0;

        // ── Pass 1: Buat invoice dari Sheet 1 "Data Invoice" ────────
        $lineNumber    = 0;
        $headerSkipped = false;
        $inChunk       = 0;

        DB::beginTransaction();
        try {
            foreach ($rows1 as $row) {
                if ($inChunk >= self::CHUNK) {
                    DB::commit();
                    $this->flushProgress($batch, $processed, $insertedCount, $skippedCount, $errors);
                    DB::beginTransaction();
                    $inChunk = 0;
                }
                $lineNumber++;
                $processed++;
                $inChunk++;

                $firstCell = trim((string) ($row[0] ?? ''));
                if (str_starts_with($firstCell, '#')) continue;
                if (!$headerSkipped) { $headerSkipped = true; continue; }
                if (str_starts_with($firstCell, '[CONTOH]')) continue;
                if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) continue;

                $noUrut       = $this->importStr($row[0] ?? '');
                $namaKlien    = $this->importStr($row[1] ?? '');
                $tanggalKirim = $this->importDate($row[2] ?? '');
                $noSuratJalan = $this->importStr($row[3] ?? '');
                $jatuhTempo   = $this->importDate($row[4] ?? '');
                $periodeAwal  = $this->importDate($row[5] ?? '');
                $periodeAkhir = $this->importDate($row[6] ?? '');

                $totalData++;

                if (!$noUrut) {
                    $errors[] = ['sheet' => 'Data Invoice', 'row' => $lineNumber, 'message' => 'no_urut wajib diisi'];
                    continue;
                }
                if (!$namaKlien) {
                    $errors[] = ['sheet' => 'Data Invoice', 'row' => $lineNumber, 'message' => "no_urut '{$noUrut}': nama_klien wajib diisi"];
                    continue;
                }
                if (!$tanggalKirim) {
                    $errors[] = ['sheet' => 'Data Invoice', 'row' => $lineNumber, 'message' => "no_urut '{$noUrut}': tanggal_kirim_barang wajib diisi"];
                    continue;
                }

                $klien = $klienMap[$namaKlien] ?? null;
                if (!$klien) {
                    $errors[] = ['sheet' => 'Data Invoice', 'row' => $lineNumber, 'message' => "Klien '{$namaKlien}' tidak ditemukan di sistem"];
                    continue;
                }

                if (!$periodeAwal)  $periodeAwal  = Carbon::parse($tanggalKirim)->startOfMonth()->format('Y-m-d');
                if (!$periodeAkhir) $periodeAkhir = Carbon::parse($tanggalKirim)->endOfMonth()->format('Y-m-d');

                $existingInvoice = Invoice::where('klien_ar_id', $klien->id)
                    ->whereDate('tanggal_kirim_barang', $tanggalKirim)
                    ->where('is_opening_balance', false)
                    ->first();
                if ($existingInvoice) {
                    $skippedUruts[$noUrut] = true;
                    $skippedCount++;
                    continue;
                }

                $carryover = $this->service->getMonthlyCarryover($klien->id, $tanggalKirim);
                $noInvKons = $this->service->generateConsolidatedInvoiceNo($klien, $tanggalKirim);

                try {
                    $invoice = Invoice::create([
                        'no_invoice'                 => $noInvKons,
                        'tanggal_invoice'            => $tanggalKirim,
                        'tanggal_kirim_barang'       => $tanggalKirim,
                        'no_surat_jalan'             => $noSuratJalan ?: null,
                        'tanggal_jatuh_tempo'        => $jatuhTempo ?: null,
                        'periode_awal'               => $periodeAwal,
                        'periode_akhir'              => $periodeAkhir,
                        'klien_ar_id'                => $klien->id,
                        'resto_id'                   => null,
                        'perusahaan_id'              => $klien->perusahaan_id,
                        'karyawan_id'                => $this->service->resolveInvoiceKaryawanId(auth()->user(), $klien),
                        'subtotal'                   => 0,
                        'tagihan_periode_sebelumnya' => $carryover,
                        'total_tagihan'              => $carryover,
                        'total_pembayaran'           => 0,
                        'sisa_tagihan'               => $carryover,
                        'status'                     => 'TERKIRIM',
                        'is_opening_balance'         => false,
                        'prepared_token'             => Str::uuid()->toString(),
                        'approved_token'             => Str::uuid()->toString(),
                        'created_by'                 => auth()->id(),
                    ]);
                } catch (\Throwable $e) {
                    $errors[] = ['sheet' => 'Data Invoice', 'row' => $lineNumber, 'message' => "Gagal membuat invoice '{$noInvKons}': " . $e->getMessage()];
                    continue;
                }

                $invoiceMapping[$noUrut] = $invoice;
                $insertedCount++;
            }
            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();
            throw $e;
        }
        $this->flushProgress($batch, $processed, $insertedCount, $skippedCount, $errors);

        // ── Pass 2: Buat item dari Sheet 2 "Item Invoice" ───────────
        $lineNumber    = 0;
        $headerSkipped = false;
        $inChunk       = 0;

        DB::beginTransaction();
        try {
            foreach ($rows2 as $row) {
                if ($inChunk >= self::CHUNK) {
                    DB::commit();
                    $this->flushProgress($batch, $processed, $insertedCount, $skippedCount, $errors);
                    DB::beginTransaction();
                    $inChunk = 0;
                }
                $lineNumber++;
                $processed++;
                $inChunk++;

                $firstCell = trim((string) ($row[0] ?? ''));
                if (str_starts_with($firstCell, '#')) continue;
                if (!$headerSkipped) { $headerSkipped = true; continue; }
                if (str_starts_with($firstCell, '[CONTOH]')) continue;
                if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) continue;

                $noUrutInvoice = $this->importStr($row[0] ?? '');
                $noInvResto    = $this->importStr($row[1] ?? '');
                $kodeResto     = $this->importStr($row[2] ?? '');
                $namaResto     = $this->importStr($row[3] ?? '');
                $kodeBarang    = $this->importStr($row[4] ?? '');
                $namaBarang    = $this->importStr($row[5] ?? '');
                $qty           = $this->importNum($row[6] ?? '');
                $satuan        = $this->importStr($row[7] ?? '');
                $harga         = $this->importNum($row[8] ?? '');

                if (!$noUrutInvoice) {
                    $errors[] = ['sheet' => 'Item Invoice', 'row' => $lineNumber, 'message' => 'no_urut_invoice wajib diisi'];
                    continue;
                }
                if (!isset($invoiceMapping[$noUrutInvoice])) {
                    if (isset($skippedUruts[$noUrutInvoice])) continue;
                    $errors[] = ['sheet' => 'Item Invoice', 'row' => $lineNumber, 'message' => "no_urut_invoice '{$noUrutInvoice}' tidak ditemukan di Sheet 'Data Invoice'"];
                    continue;
                }
                if (!$namaBarang) {
                    $errors[] = ['sheet' => 'Item Invoice', 'row' => $lineNumber, 'message' => 'nama_barang wajib diisi'];
                    continue;
                }
                if ($qty <= 0) {
                    $errors[] = ['sheet' => 'Item Invoice', 'row' => $lineNumber, 'message' => 'qty harus lebih dari 0'];
                    continue;
                }

                $invoice  = $invoiceMapping[$noUrutInvoice];
                $barangId = $kodeBarang ? ($barangMap[$kodeBarang] ?? null) : null;

                $invoice->items()->create([
                    'barang_id'        => $barangId,
                    'nama_barang'      => $namaBarang,
                    'qty'              => $qty,
                    'satuan'           => $satuan  ?: null,
                    'harga_satuan'     => $harga,
                    'subtotal'         => $qty * $harga,
                    'no_invoice_resto' => $noInvResto ?: null,
                    'kode_resto'       => $kodeResto  ?: null,
                    'nama_resto'       => $namaResto  ?: null,
                ]);

                $invoicesWithItems[$noUrutInvoice] = $invoice;
            }
            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();
            throw $e;
        }
        $this->flushProgress($batch, $processed, $insertedCount, $skippedCount, $errors);

        // ── Pass 3: Hitung subtotal untuk invoice yang punya item ───
        $this->recomputeSubtotals($invoicesWithItems, fn($invoice) =>
            (float) $invoice->items()->sum('subtotal') + (float) $invoice->tagihan_periode_sebelumnya
        );

        // ── Pass 4 & 5: cascade carryover + dispatch PDF ────────────
        $this->finalizeBatch($batch, $invoiceMapping, $errors);

        $failed = max(0, $totalData - $insertedCount - $skippedCount);
        $batch->update([
            'status'     => 'completed',
            'processed'  => $batch->total,
            'total_data' => $totalData,
            'inserted'   => $insertedCount,
            'skipped'    => $skippedCount,
            'failed'     => $failed,
            'errors'     => $errors,
            'message'    => "Import B2B selesai. {$insertedCount} invoice konsolidasi ditambahkan, {$skippedCount} dilewati (sudah ada), {$failed} gagal.",
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Shared helpers
    // ──────────────────────────────────────────────────────────────

    /** Recompute subtotal & total_tagihan untuk invoice yang punya item (chunked). */
    private function recomputeSubtotals(array $invoicesWithItems, callable $totalTagihanFn): void
    {
        $i = 0;
        DB::beginTransaction();
        try {
            foreach ($invoicesWithItems as $invoice) {
                if ($i > 0 && $i % self::CHUNK === 0) {
                    DB::commit();
                    DB::beginTransaction();
                }
                $i++;
                $subtotal     = (float) $invoice->items()->sum('subtotal');
                $totalTagihan = $totalTagihanFn($invoice);
                $invoice->update([
                    'subtotal'      => $subtotal,
                    'total_tagihan' => $totalTagihan,
                    'sisa_tagihan'  => $totalTagihan,
                    'updated_by'    => auth()->id(),
                ]);
            }
            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();
            throw $e;
        }
    }

    /**
     * Propagasi carryover antar invoice dalam batch + dispatch upload PDF.
     * Non-fatal: kegagalan dicatat ke $errors tetapi tidak menggagalkan batch
     * (invoice sudah tersimpan pada pass sebelumnya).
     */
    private function finalizeBatch(InvoiceImportBatch $batch, array $invoiceMapping, array &$errors): void
    {
        // Tentukan invoice pertama per klien sebagai titik awal cascade.
        $firstByKlien = [];
        foreach ($invoiceMapping as $invoice) {
            $klienId = $invoice->klien_ar_id;
            if (
                !isset($firstByKlien[$klienId]) ||
                $invoice->tanggal_invoice < $firstByKlien[$klienId]->tanggal_invoice ||
                ($invoice->tanggal_invoice === $firstByKlien[$klienId]->tanggal_invoice
                    && $invoice->id < $firstByKlien[$klienId]->id)
            ) {
                $firstByKlien[$klienId] = $invoice;
            }
        }

        // Heartbeat: fase ini tidak meng-update progress, jadi sentuh updated_at
        // berkala agar batch tidak salah ditandai 'stale' oleh failStale().
        $i = 0;
        foreach ($firstByKlien as $firstInvoice) {
            if (++$i % 25 === 0) $batch->touch();
            try {
                DB::transaction(fn() => $this->service->propagateCarryover($firstInvoice->fresh()));
            } catch (\Throwable $e) {
                Log::error('ImportInvoice: propagateCarryover gagal', ['invoice_id' => $firstInvoice->id, 'error' => $e->getMessage()]);
                $errors[] = ['sheet' => 'Carryover', 'row' => 0, 'message' => "Gagal propagasi carryover klien #{$firstInvoice->klien_ar_id}: " . $e->getMessage()];
            }
        }

        // Dispatch upload PDF SETELAH propagasi carryover selesai.
        $i = 0;
        foreach ($invoiceMapping as $invoice) {
            if (++$i % 200 === 0) $batch->touch();
            try {
                UploadInvoiceToGDriveJob::dispatch($invoice->fresh()->id);
            } catch (\Throwable $e) {
                Log::error('ImportInvoice: dispatch upload PDF gagal', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            }
        }

        // Re-upload OB invoices agar bagian "Invoice Bulan Berjalan" terupdate.
        $klienIds = collect(array_values($firstByKlien))->pluck('klien_ar_id')->unique()->toArray();
        if (!empty($klienIds)) {
            Invoice::whereIn('klien_ar_id', $klienIds)
                ->where('is_opening_balance', true)
                ->where('approval_status', 'APPROVED')
                ->each(fn($ob) => UploadInvoiceToGDriveJob::dispatch($ob->id));
        }
    }

    private function flushProgress(InvoiceImportBatch $batch, int $processed, int $inserted, int $skipped, array $errors): void
    {
        $batch->update([
            'processed' => min($processed, $batch->total),
            'inserted'  => $inserted,
            'skipped'   => $skipped,
            'failed'    => count($errors),
        ]);
    }

    /** @return array<string,KlienAr> nama_klien => KlienAr (occurrence pertama). */
    private function buildKlienMap(): array
    {
        $map = [];
        foreach (KlienAr::with('perusahaan')->get() as $klien) {
            if ($klien->nama_klien !== null && !isset($map[$klien->nama_klien])) {
                $map[$klien->nama_klien] = $klien;
            }
        }
        return $map;
    }

    /** @return array<string,int> kode_barang => id (occurrence pertama). */
    private function buildBarangMap(): array
    {
        $map = [];
        foreach (Barang::all(['id', 'kode_barang']) as $barang) {
            if ($barang->kode_barang !== null && !isset($map[$barang->kode_barang])) {
                $map[$barang->kode_barang] = $barang->id;
            }
        }
        return $map;
    }

    // ──────────────────────────────────────────────────────────────
    //  Parsing (dipindahkan dari InvoiceController)
    // ──────────────────────────────────────────────────────────────

    private function parseXlsxSheet(Worksheet $sheet, string $firstHeader): array
    {
        $rows        = [];
        $headerFound = false;

        foreach ($sheet->getRowIterator() as $rowObj) {
            $cellIter = $rowObj->getCellIterator();
            $cellIter->setIterateOnlyExistingCells(false);

            $cells = [];
            foreach ($cellIter as $cell) {
                $cells[] = $this->xlsxCellStr($cell);
            }

            $firstCell = trim($cells[0] ?? '');

            if (!$headerFound) {
                if (strtolower($firstCell) === strtolower($firstHeader)) {
                    $headerFound = true;
                    $rows[]      = $cells;
                }
                continue;
            }

            $rows[] = $cells;
        }

        return $rows;
    }

    private function parseCsv(string $path): array
    {
        $rows   = [];
        $handle = fopen($path, 'r');
        $bom    = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }

    private function xlsxCellStr(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): string
    {
        $value = $cell->getValue();
        if ($value === null) return '';
        if (is_bool($value)) return $value ? '1' : '0';

        if (is_numeric($value)) {
            $formatCode = $cell->getStyle()->getNumberFormat()->getFormatCode();
            if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTimeFormatCode($formatCode)) {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)
                    ->format('Y-m-d');
            }
        }

        if (is_int($value)) return (string) $value;
        if (is_float($value)) {
            return fmod($value, 1.0) === 0.0 ? sprintf('%.0f', $value) : (string) $value;
        }
        return trim((string) $value);
    }

    private function importStr(mixed $val): ?string
    {
        $s = trim((string) $val);
        return ($s === '' || $s === '-') ? null : $s;
    }

    private function importDate(mixed $val): ?string
    {
        $s = trim((string) $val);
        if ($s === '' || $s === '-') return null;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;

        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return $s;
    }

    private function importNum(mixed $val): float
    {
        $s = trim((string) $val);
        $s = str_replace(['.', ','], ['', '.'], $s);
        return is_numeric($s) ? (float) $s : 0.0;
    }
}
