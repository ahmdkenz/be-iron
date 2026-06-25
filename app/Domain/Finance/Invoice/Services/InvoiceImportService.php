<?php

namespace App\Domain\Finance\Invoice\Services;

use App\Domain\Finance\Invoice\Jobs\UploadInvoiceToGDriveJob;
use App\Models\Barang;
use App\Models\EndingBalance;
use App\Models\Invoice;
use App\Models\InvoiceImportBatch;
use App\Models\InvoiceItem;
use App\Models\KlienAr;
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
 *  - Preload LOCKED EndingBalance per klien ke memori (hindari N+1 query cek EB-lock)
 *  - Commit bertahap per CHUNK invoice (bukan satu transaksi raksasa)
 *  - Update progress ke InvoiceImportBatch agar bisa dipantau frontend
 *
 * Re-import (upsert): jika invoice (klien + tanggal) sudah ada dan status != LUNAS
 * dan periode-nya tidak di-LOCK di EndingBalance, item lama dihapus dan diganti baru,
 * lalu payment + PDM + EB-DRAFT di-recalculate otomatis.
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
        $klienMap    = $this->buildKlienMap();
        $barangMap   = $this->buildBarangMap();
        $lockedEbMap = $this->buildLockedEbMap();

        if ($batch->type === 'b2b') {
            $this->importB2B($batch, $rows1, $rows2, $klienMap, $barangMap, $lockedEbMap);
        } else {
            $this->importB2C($batch, $rows1, $rows2, $klienMap, $barangMap, $lockedEbMap);
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Import B2C
    // ──────────────────────────────────────────────────────────────
    private function importB2C(
        InvoiceImportBatch $batch,
        array $rows1,
        array $rows2,
        array $klienMap,
        array $barangMap,
        array $lockedEbMap
    ): void {
        $insertedCount   = 0;
        $updatedCount    = 0;
        $skippedCount    = 0;
        $totalData       = 0;
        $errors          = [];
        $invoiceMapping  = [];  // noUrut => Invoice object (baru ATAU existing yg di-update)
        $updateMapping   = [];  // noUrut => Invoice object (hanya existing yg di-update)
        $skippedUruts    = [];
        $processed       = 0;

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
                $jatuhTempo   = $this->importDate($row[3] ?? '');  // D: tanggal_jatuh_tempo
                $noSuratJalan = $this->importStr($row[4] ?? '');   // E: no_surat_jalan
                $keterangan   = $this->importStr($row[5] ?? '');   // F: keterangan

                $validated = Validator::make(
                    [
                        'no_urut'         => $noUrut,
                        'nama_klien'      => $namaKlien,
                        'tanggal_invoice' => $tanggal,
                    ],
                    [
                        'no_urut'         => ['required'],
                        'nama_klien'      => ['required'],
                        'tanggal_invoice' => ['required', 'date'],
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

                $existingInvoice = Invoice::where('klien_ar_id', $klien->id)
                    ->whereDate('tanggal_invoice', $tanggal)
                    ->where('is_opening_balance', false)
                    ->first();

                if ($existingInvoice) {
                    // Invoice LUNAS tidak boleh diubah via import
                    if ($existingInvoice->status === 'LUNAS') {
                        $skippedUruts[$noUrut] = true;
                        $skippedCount++;
                        continue;
                    }
                    // Periode sudah di-LOCK di Ending Balance → tidak boleh diubah
                    if ($this->isEbLocked($lockedEbMap, $klien->id, $tanggal)) {
                        $skippedUruts[$noUrut] = true;
                        $skippedCount++;
                        $errors[] = [
                            'sheet'   => 'Invoice',
                            'row'     => $lineNumber,
                            'message' => "Invoice {$existingInvoice->no_invoice} tidak dapat diupdate — periode sudah dikunci di Ending Balance.",
                        ];
                        continue;
                    }
                    // DRAFT / TERKIRIM / SEBAGIAN → tandai untuk UPDATE (item diganti)
                    $updateMapping[$noUrut]  = $existingInvoice;
                    $invoiceMapping[$noUrut] = $existingInvoice;
                    continue;
                }

                $carryover = $this->service->getMonthlyCarryover($klien->id, $tanggal);
                $noInvoice = $this->service->generateNoInvoice($klien, $tanggal);

                try {
                    $invoice = Invoice::create([
                        'no_invoice'                 => $noInvoice,
                        'tanggal_invoice'            => $tanggal,
                        'tanggal_jatuh_tempo'        => $jatuhTempo,
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
        $deletedItemsFor   = [];  // invoice_id => true (sudah delete item lama)
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

                // Untuk invoice yang di-update, hapus item lama sekali saja sebelum insert baru
                if (isset($updateMapping[$noUrutInvoice]) && !isset($deletedItemsFor[$invoice->id])) {
                    InvoiceItem::where('invoice_id', $invoice->id)->delete();
                    $deletedItemsFor[$invoice->id] = true;
                }

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

        // ── Pass 3.5: Post-process invoices yang di-update ──────────
        $updatedCount = $this->postProcessUpdated($updateMapping, $invoicesWithItems, $errors);
        $batch->update(['updated' => $updatedCount]);

        // ── Pass 4 & 5: cascade carryover + dispatch PDF ────────────
        // Carryover hanya dijalankan untuk invoice BARU; invoice yang di-update
        // sudah ter-cascade melalui recalculate() di postProcessUpdated.
        $newInvoiceMapping = array_diff_key($invoiceMapping, $updateMapping);
        $this->finalizeBatch($batch, $newInvoiceMapping, $errors);

        // GDrive upload untuk invoice yang di-update
        foreach ($updateMapping as $updatedInvoice) {
            try {
                UploadInvoiceToGDriveJob::dispatch($updatedInvoice->id);
            } catch (\Throwable $e) {
                Log::error('ImportInvoice: dispatch upload PDF (update) gagal', ['invoice_id' => $updatedInvoice->id, 'error' => $e->getMessage()]);
            }
        }

        $failed = max(0, $totalData - $insertedCount - $updatedCount - $skippedCount);
        $batch->update([
            'status'     => 'completed',
            'processed'  => $batch->total,
            'total_data' => $totalData,
            'inserted'   => $insertedCount,
            'updated'    => $updatedCount,
            'skipped'    => $skippedCount,
            'failed'     => $failed,
            'errors'     => $errors,
            'message'    => "Import selesai. {$insertedCount} ditambahkan, {$updatedCount} diperbarui, {$skippedCount} dilewati, {$failed} gagal.",
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Import B2B
    // ──────────────────────────────────────────────────────────────
    private function importB2B(
        InvoiceImportBatch $batch,
        array $rows1,
        array $rows2,
        array $klienMap,
        array $barangMap,
        array $lockedEbMap
    ): void {
        $insertedCount     = 0;
        $updatedCount      = 0;
        $skippedCount      = 0;
        $totalData         = 0;
        $errors            = [];
        $invoiceMapping    = [];
        $updateMapping     = [];
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
                $tanggal      = $this->importDate($row[2] ?? '');
                $noSuratJalan = $this->importStr($row[3] ?? '');
                $jatuhTempo   = $this->importDate($row[4] ?? '');

                $totalData++;

                if (!$noUrut) {
                    $errors[] = ['sheet' => 'Data Invoice', 'row' => $lineNumber, 'message' => 'no_urut wajib diisi'];
                    continue;
                }
                if (!$namaKlien) {
                    $errors[] = ['sheet' => 'Data Invoice', 'row' => $lineNumber, 'message' => "no_urut '{$noUrut}': nama_klien wajib diisi"];
                    continue;
                }
                if (!$tanggal) {
                    $errors[] = ['sheet' => 'Data Invoice', 'row' => $lineNumber, 'message' => "no_urut '{$noUrut}': tanggal_invoice wajib diisi"];
                    continue;
                }

                $klien = $klienMap[$namaKlien] ?? null;
                if (!$klien) {
                    $errors[] = ['sheet' => 'Data Invoice', 'row' => $lineNumber, 'message' => "Klien '{$namaKlien}' tidak ditemukan di sistem"];
                    continue;
                }

                $existingInvoice = Invoice::where('klien_ar_id', $klien->id)
                    ->whereDate('tanggal_invoice', $tanggal)
                    ->where('is_opening_balance', false)
                    ->first();

                if ($existingInvoice) {
                    if ($existingInvoice->status === 'LUNAS') {
                        $skippedUruts[$noUrut] = true;
                        $skippedCount++;
                        continue;
                    }
                    if ($this->isEbLocked($lockedEbMap, $klien->id, $tanggal)) {
                        $skippedUruts[$noUrut] = true;
                        $skippedCount++;
                        $errors[] = [
                            'sheet'   => 'Data Invoice',
                            'row'     => $lineNumber,
                            'message' => "Invoice {$existingInvoice->no_invoice} tidak dapat diupdate — periode sudah dikunci di Ending Balance.",
                        ];
                        continue;
                    }
                    $updateMapping[$noUrut]  = $existingInvoice;
                    $invoiceMapping[$noUrut] = $existingInvoice;
                    continue;
                }

                $carryover = $this->service->getMonthlyCarryover($klien->id, $tanggal);
                $noInvKons = $this->service->generateConsolidatedInvoiceNo($klien, $tanggal);

                try {
                    $invoice = Invoice::create([
                        'no_invoice'                 => $noInvKons,
                        'tanggal_invoice'            => $tanggal,
                        'no_surat_jalan'             => $noSuratJalan ?: null,
                        'tanggal_jatuh_tempo'        => $jatuhTempo ?: null,
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
        $deletedItemsFor = [];
        $lineNumber      = 0;
        $headerSkipped   = false;
        $inChunk         = 0;

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

                // Untuk invoice yang di-update, hapus item lama sekali saja sebelum insert baru
                if (isset($updateMapping[$noUrutInvoice]) && !isset($deletedItemsFor[$invoice->id])) {
                    InvoiceItem::where('invoice_id', $invoice->id)->delete();
                    $deletedItemsFor[$invoice->id] = true;
                }

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

        // ── Pass 3.5: Post-process invoices yang di-update ──────────
        $updatedCount = $this->postProcessUpdated($updateMapping, $invoicesWithItems, $errors);
        $batch->update(['updated' => $updatedCount]);

        // ── Pass 4 & 5: cascade carryover + dispatch PDF ────────────
        $newInvoiceMapping = array_diff_key($invoiceMapping, $updateMapping);
        $this->finalizeBatch($batch, $newInvoiceMapping, $errors);

        // GDrive upload untuk invoice yang di-update
        foreach ($updateMapping as $updatedInvoice) {
            try {
                UploadInvoiceToGDriveJob::dispatch($updatedInvoice->id);
            } catch (\Throwable $e) {
                Log::error('ImportInvoice: dispatch upload PDF (update) gagal', ['invoice_id' => $updatedInvoice->id, 'error' => $e->getMessage()]);
            }
        }

        $failed = max(0, $totalData - $insertedCount - $updatedCount - $skippedCount);
        $batch->update([
            'status'     => 'completed',
            'processed'  => $batch->total,
            'total_data' => $totalData,
            'inserted'   => $insertedCount,
            'updated'    => $updatedCount,
            'skipped'    => $skippedCount,
            'failed'     => $failed,
            'errors'     => $errors,
            'message'    => "Import B2B selesai. {$insertedCount} invoice konsolidasi ditambahkan, {$updatedCount} diperbarui, {$skippedCount} dilewati, {$failed} gagal.",
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Shared helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Jalankan recalculate + auto-PDM + EB-DRAFT recalculate untuk setiap invoice
     * yang berhasil di-update (ada di $updateMapping DAN punya item baru di $invoicesWithItems).
     */
    private function postProcessUpdated(array $updateMapping, array $invoicesWithItems, array &$errors): int
    {
        $count = 0;

        // Kumpulkan invoice ID yang benar-benar punya item baru
        $toProcess = [];
        foreach ($updateMapping as $noUrut => $existingInvoice) {
            if (isset($invoicesWithItems[$noUrut])) {
                $toProcess[$existingInvoice->id] = $existingInvoice;
            }
        }

        foreach (array_chunk($toProcess, 20, true) as $chunk) {
            foreach ($chunk as $invoiceId => $invoiceObj) {
                $invoice = Invoice::find($invoiceId);
                if (!$invoice) continue;

                try {
                    DB::transaction(fn() => $this->service->recalculate($invoice));
                    $invoice->refresh();

                    // Auto-PDM jika invoice menjadi overpaid setelah item diganti
                    if ((float) $invoice->total_pembayaran > (float) $invoice->subtotal
                        && (float) $invoice->subtotal > 0
                    ) {
                        $this->service->handleExcessPaymentAfterUpdate($invoice);
                    }

                    // Recalculate DRAFT EndingBalance yang terdampak
                    $this->service->recalculateDraftEndingBalance($invoice);

                    $count++;
                } catch (\Throwable $e) {
                    Log::error('ImportInvoice: postProcessUpdated gagal', ['invoice_id' => $invoiceId, 'error' => $e->getMessage()]);
                    $errors[] = ['sheet' => 'Update', 'row' => 0, 'message' => "Gagal recalculate invoice #{$invoiceId}: " . $e->getMessage()];
                }
            }
        }

        return $count;
    }

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
     * Non-fatal: kegagalan dicatat ke $errors tetapi tidak menggagalkan batch.
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

        $i = 0;
        foreach ($invoiceMapping as $invoice) {
            if (++$i % 200 === 0) $batch->touch();
            try {
                UploadInvoiceToGDriveJob::dispatch($invoice->fresh()->id);
            } catch (\Throwable $e) {
                Log::error('ImportInvoice: dispatch upload PDF gagal', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            }
        }

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

    /**
     * Pre-load semua LOCKED EndingBalance ke memory:
     * [klien_ar_id => [['awal' => 'Y-m-d', 'akhir' => 'Y-m-d'], ...]]
     */
    private function buildLockedEbMap(): array
    {
        $map = [];
        EndingBalance::where('status', 'LOCKED')
            ->select(['klien_ar_id', 'periode_awal', 'periode_akhir'])
            ->get()
            ->each(function ($eb) use (&$map) {
                $map[$eb->klien_ar_id][] = [
                    'awal'  => (string) $eb->periode_awal,
                    'akhir' => (string) $eb->periode_akhir,
                ];
            });
        return $map;
    }

    /** Cek apakah tanggal tertentu masuk dalam salah satu periode EB yang LOCKED untuk klien ini. */
    private function isEbLocked(array $lockedEbMap, int $klienArId, string $tanggal): bool
    {
        foreach ($lockedEbMap[$klienArId] ?? [] as $range) {
            if ($tanggal >= $range['awal'] && $tanggal <= $range['akhir']) {
                return true;
            }
        }
        return false;
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
