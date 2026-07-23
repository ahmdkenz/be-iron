<?php

namespace App\Domain\Finance\ApShz360Sync\Services;

use App\Domain\Finance\ApShz360Sync\Exceptions\ApShz360SyncInProgressException;
use App\Domain\Finance\ApShz360Sync\Support\VendorNameMatcher;
use App\Models\ApShz360PoImport;
use App\Models\ApShz360PoImportItem;
use App\Models\ApShz360ReceiptImport;
use App\Models\ApShz360SyncRun;
use App\Models\ApSourceVendorMap;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class ApShz360SyncService
{
    private const LOCK_KEY = 'ap-shz360-sync-full-lock';

    // Failsafe kalau proses crash tanpa sempat release lock (bukan waktu tunggu normal).
    private const LOCK_TIMEOUT_SECONDS = 600;

    public function __construct(private readonly Shz360ApiClient $client) {}

    /**
     * Orkestrasi 1 siklus sync penuh (dipakai command terjadwal & tombol retry manual).
     * Mundur 1 menit dari run sukses terakhir sebagai buffer supaya tidak ada
     * perubahan yang jatuh tepat di batas window dan terlewat.
     *
     * Baseline updated_since HANYA diambil dari run berstatus success (bersih tanpa
     * error baris). Run partial_success sengaja tidak menjadi baseline, supaya baris
     * yang gagal diproses tetap eligible untuk ditarik ulang di sync berikutnya.
     *
     * @throws ApShz360SyncInProgressException jika sync lain (scheduler/manual) masih berjalan.
     */
    public function runFullSync(bool $forceFullResync = false): ApShz360SyncRun
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TIMEOUT_SECONDS);

        if (! $lock->get()) {
            throw new ApShz360SyncInProgressException();
        }

        try {
            // Lock baru saja didapat secara eksklusif, jadi row 'running' yang masih
            // tersisa di sini pasti bukan sync yang sedang aktif — peninggalan proses
            // yang mati tak wajar (timeout/crash) sebelum sempat masuk ke catch/finally.
            ApShz360SyncRun::where('status', 'running')->update([
                'status' => 'failed',
                'finished_at' => now(),
                'message' => 'Sync sebelumnya terhenti tak terduga (proses berhenti sebelum selesai) — ditandai gagal otomatis oleh sync berikutnya.',
            ]);

            $run = ApShz360SyncRun::create(['started_at' => now(), 'status' => 'running']);

            // Full resync sengaja abaikan baseline sama sekali (persis perilaku
            // run pertama kali) — tarik semua yang match status saat ini apa pun
            // umur updated_at-nya, supaya PO/Terima PO lama yang kelewat gap bisa
            // ditarik ulang tanpa perlu reset tb_ap_shz360_sync_runs manual.
            $updatedSince = null;
            if (! $forceFullResync) {
                $lastSuccess = ApShz360SyncRun::where('status', 'success')
                    ->where('id', '!=', $run->id)
                    ->latest('started_at')
                    ->first();
                $updatedSince = $lastSuccess?->started_at?->subMinute()->toIso8601String();
            }

            try {
                $this->syncPurchaseOrders($run, $updatedSince);
                $this->syncTerimaPos($run, $updatedSince);

                $errorCount = $run->errors()->count();
                $run->update([
                    'status' => $errorCount > 0 ? 'partial_success' : 'success',
                    'finished_at' => now(),
                    'message' => $errorCount > 0 ? "Sync selesai sebagian, ada {$errorCount} baris gagal." : null,
                ]);
            } catch (Throwable $e) {
                $run->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'message' => $e->getMessage(),
                ]);
            }

            return $run->refresh();
        } finally {
            $lock->release();
        }
    }

    public function syncPurchaseOrders(ApShz360SyncRun $run, ?string $updatedSince = null): void
    {
        $params = $updatedSince ? ['updated_since' => $updatedSince] : [];
        $rows = $this->client->getPurchaseOrders($params);
        $run->increment('po_fetched', count($rows));

        foreach ($rows as $row) {
            try {
                $upserted = DB::transaction(fn () => $this->upsertPo($row));
                if ($upserted) {
                    $run->increment('po_upserted');
                }
            } catch (Throwable $e) {
                $this->logError($run, 'PO', $row['source_po_id'] ?? null, $e->getMessage(), $row);
            }
        }
    }

    public function syncTerimaPos(ApShz360SyncRun $run, ?string $updatedSince = null): void
    {
        $params = $updatedSince ? ['updated_since' => $updatedSince] : [];
        $rows = $this->client->getTerimaPos($params);
        $run->increment('receipt_fetched', count($rows));

        foreach ($rows as $row) {
            try {
                $upserted = DB::transaction(fn () => $this->upsertReceipt($row));
                if ($upserted) {
                    $run->increment('receipt_upserted');
                }
            } catch (Throwable $e) {
                $this->logError($run, 'TERIMA_PO', $row['source_receipt_id'] ?? null, $e->getMessage(), $row);
            }
        }
    }

    private function upsertPo(array $row): bool
    {
        $hash = $this->hash($row);
        $existing = ApShz360PoImport::where('source_po_id', $row['source_po_id'])->first();

        if ($existing && $existing->source_hash === $hash) {
            return false;
        }

        $vendorMap = $this->upsertVendorMap($row['supplier'] ?? null);

        // Jangan mundurkan status kalau sudah diproses staff (CONVERTED/IGNORED),
        // meski datanya berubah lagi di SHZ360 setelahnya.
        $importStatus = $existing && in_array($existing->import_status, ['CONVERTED', 'IGNORED'], true)
            ? $existing->import_status
            : ($vendorMap?->vendor_ap_id ? 'READY_FOR_AP' : 'NEED_MAPPING');

        $po = ApShz360PoImport::updateOrCreate(
            ['source_po_id' => $row['source_po_id']],
            [
                'kode_po' => $row['kode_po'],
                'tanggal_po' => $row['tanggal_po'] ?? null,
                'source_supplier_id' => $row['supplier']['source_supplier_id'] ?? null,
                'vendor_ap_id' => $vendorMap?->vendor_ap_id,
                'status_po' => $row['status'] ?? null,
                'subtotal' => $row['subtotal'] ?? 0,
                'ongkir' => $row['ongkir'] ?? 0,
                'diskon' => $row['diskon'] ?? 0,
                'grand_total' => $row['grand_total'] ?? 0,
                'source_updated_at' => $row['updated_at'] ?? null,
                'source_hash' => $hash,
                'import_status' => $importStatus,
                'raw_payload' => $row,
            ]
        );

        $po->items()->delete();
        foreach ($row['items'] ?? [] as $item) {
            $po->items()->create([
                'source_po_item_id' => $item['source_po_item_id'] ?? null,
                'source_barang_id' => $item['source_barang_id'] ?? null,
                'kode_barang' => $item['kode_barang'] ?? null,
                'nama_barang' => $item['nama_barang'] ?? '',
                'satuan' => $item['satuan'] ?? null,
                'qty_po' => $item['qty_po'] ?? 0,
                'harga' => $item['harga'] ?? 0,
                'ppn' => $item['ppn'] ?? 0,
                'subtotal' => $item['subtotal'] ?? 0,
                'raw_payload' => $item,
            ]);
        }

        return true;
    }

    private function upsertReceipt(array $row): bool
    {
        $hash = $this->hash($row);
        $existing = ApShz360ReceiptImport::where('source_receipt_id', $row['source_receipt_id'])->first();
        $poImport = ApShz360PoImport::where('source_po_id', $row['source_po_id'] ?? null)->first();

        // Skip beneran kalau data receipt tidak berubah DAN (linkage ke PO sudah
        // ada ATAU memang masih belum bisa diisi sekarang). Selain itu lanjut,
        // supaya po_import_id yang sempat kosong bisa nyambung sendiri begitu
        // PO-nya tersedia di sync berikutnya (source_hash saja tidak cukup jadi
        // penanda "tidak ada yang perlu diupdate").
        if ($existing && $existing->source_hash === $hash && ($existing->po_import_id !== null || ! $poImport)) {
            return false;
        }

        $importStatus = $existing && in_array($existing->import_status, ['CONVERTED', 'IGNORED'], true)
            ? $existing->import_status
            : ($poImport && $poImport->vendor_ap_id ? 'READY_FOR_AP' : 'NEW');

        $receipt = ApShz360ReceiptImport::updateOrCreate(
            ['source_receipt_id' => $row['source_receipt_id']],
            [
                'po_import_id' => $poImport?->id,
                'kode_receipt' => $row['kode_receipt'],
                'tanggal_receipt' => $row['tanggal_receipt'] ?? null,
                'no_invoice' => $row['no_invoice'] ?? null,
                'no_surat_jalan' => $row['no_surat_jalan'] ?? null,
                'no_faktur_pajak' => $row['no_faktur_pajak'] ?? null,
                'source_updated_at' => $row['updated_at'] ?? null,
                'source_hash' => $hash,
                'import_status' => $importStatus,
                'raw_payload' => $row,
            ]
        );

        $receipt->items()->delete();
        foreach ($row['items'] ?? [] as $item) {
            $poItem = $poImport && ($item['source_po_item_id'] ?? null)
                ? ApShz360PoImportItem::where('po_import_id', $poImport->id)
                    ->where('source_po_item_id', $item['source_po_item_id'])
                    ->first()
                : null;

            $receipt->items()->create([
                'po_item_import_id' => $poItem?->id,
                'source_receipt_item_id' => $item['source_receipt_item_id'] ?? null,
                'source_barang_id' => $item['source_barang_id'] ?? null,
                'kode_barang' => $item['kode_barang'] ?? null,
                'nama_barang' => $item['nama_barang'] ?? '',
                'satuan' => $item['satuan'] ?? null,
                'status_detail_terima_po' => $item['status_detail_terima_po'] ?? null,
                'qty_diterima' => $item['qty_diterima'] ?? 0,
                'qty_tolak' => $item['qty_tolak'] ?? 0,
                'keterangan_tolak' => $item['keterangan_tolak'] ?? null,
                'harga' => $item['harga'] ?? 0,
                'subtotal' => $item['subtotal'] ?? 0,
                'raw_payload' => $item,
            ]);
        }

        return true;
    }

    private function upsertVendorMap(?array $supplier): ?ApSourceVendorMap
    {
        if (! $supplier || ! ($supplier['source_supplier_id'] ?? null)) {
            return null;
        }

        $map = ApSourceVendorMap::updateOrCreate(
            ['source_system' => 'SHZ360', 'source_supplier_id' => $supplier['source_supplier_id']],
            [
                'source_supplier_code' => $supplier['kode_supplier'] ?? null,
                'source_supplier_name' => $supplier['nama_supplier'] ?? null,
                'raw_payload' => $supplier,
                'last_synced_at' => now(),
            ]
        );

        // Supplier_id baru dari SHZ360 tapi namanya sudah pernah dipetakan sebelumnya
        // (di supplier_id lain) langsung ikut ke-resolve, tanpa perlu mapping manual lagi.
        if (! $map->vendor_ap_id && $map->source_supplier_name) {
            $normalized = VendorNameMatcher::normalize($map->source_supplier_name);

            $knownVendorApId = ApSourceVendorMap::where('source_system', 'SHZ360')
                ->whereNotNull('vendor_ap_id')
                ->whereRaw('UPPER(TRIM(source_supplier_name)) = ?', [$normalized])
                ->value('vendor_ap_id');

            if ($knownVendorApId) {
                $map->update(['vendor_ap_id' => $knownVendorApId, 'status' => 'MAPPED']);
            }
        }

        return $map;
    }

    private function hash(array $row): string
    {
        return hash('sha256', json_encode($row));
    }

    private function logError(ApShz360SyncRun $run, string $sourceType, ?int $sourceId, string $message, array $context): void
    {
        $run->errors()->create([
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'message' => $message,
            'context' => $context,
        ]);
    }
}
