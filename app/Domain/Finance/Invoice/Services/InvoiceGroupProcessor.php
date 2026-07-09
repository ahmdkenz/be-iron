<?php

namespace App\Domain\Finance\Invoice\Services;

use App\Models\EndingBalance;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\KlienAr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Memproses satu grup invoice (header + items sudah terkumpul) secara atomik.
 *
 * Digunakan oleh MasterImportService (sheet Master Invoice) dan dapat dipakai
 * kembali oleh InvoiceImportService untuk logika EB-lock dan LUNAS guard.
 *
 * Tanggung jawab:
 *  - Cek periode EB LOCKED → skip
 *  - Cari invoice existing berdasarkan klien_ar_id + tanggal_invoice
 *  - Invoice LUNAS → skip
 *  - Invoice baru: create + insert items + recompute subtotal
 *  - Invoice update: delete items lama + insert baru + recalculate
 *
 * TIDAK menangani:
 *  - Parsing Excel / grouping baris (tanggung jawab caller)
 *  - propagateCarryover untuk invoice baru (caller memanggil setelah semua grup selesai)
 */
class InvoiceGroupProcessor
{
    public function __construct(private readonly InvoiceService $service) {}

    // ──────────────────────────────────────────────────────────────
    //  Public: proses satu grup
    // ──────────────────────────────────────────────────────────────

    /**
     * Proses satu grup invoice dari flat-row data.
     *
     * @param  string $tipeInvoice  'B2B' atau 'B2C'
     * @param  array  $headerData   Keys: klien_ar_id, tanggal_invoice, tanggal_jatuh_tempo?,
     *                              no_surat_jalan?, keterangan?
     * @param  array  $items        Array of item arrays. Keys per item:
     *                              barang_id?, kode_barang?, nama_barang, qty, satuan?,
     *                              harga_satuan, no_invoice_resto?, kode_resto?, nama_resto?
     * @param  array  $lockedEbMap  Preloaded map dari buildLockedEbMap()
     * @return ProcessGroupResult
     */
    public function processGroup(
        string $tipeInvoice,
        array $headerData,
        array $items,
        array $lockedEbMap,
    ): ProcessGroupResult {
        $klienArId = (int) $headerData['klien_ar_id'];
        $tanggal   = $headerData['tanggal_invoice'];

        if ($this->isEbLocked($lockedEbMap, $klienArId, $tanggal)) {
            return ProcessGroupResult::skipped('Periode sudah dikunci di Ending Balance');
        }

        $existingInvoice = Invoice::where('klien_ar_id', $klienArId)
            ->whereDate('tanggal_invoice', $tanggal)
            ->where('is_opening_balance', false)
            ->first();

        if ($existingInvoice) {
            if ($existingInvoice->status === 'LUNAS') {
                return ProcessGroupResult::skipped("Invoice {$existingInvoice->no_invoice} sudah LUNAS");
            }

            return $this->updateInvoice($existingInvoice, $items);
        }

        return $this->createInvoice($tipeInvoice, $klienArId, $headerData, $items);
    }

    /**
     * Propagasi carryover untuk sekumpulan invoice baru setelah semua grup selesai.
     * Panggil ini setelah loop grup selesai, dengan daftar invoice yang berhasil dibuat.
     *
     * @param Invoice[] $newInvoices
     */
    public function propagateCarryoverForNew(array $newInvoices): void
    {
        // Tentukan invoice paling awal per klien sebagai titik mulai cascade
        $firstByKlien = [];
        foreach ($newInvoices as $invoice) {
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

        foreach ($firstByKlien as $firstInvoice) {
            try {
                DB::transaction(fn() => $this->service->propagateCarryover($firstInvoice->fresh()));
            } catch (\Throwable $e) {
                Log::error('InvoiceGroupProcessor: propagateCarryover gagal', [
                    'invoice_id' => $firstInvoice->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Public: helpers untuk preload (dapat dipakai caller)
    // ──────────────────────────────────────────────────────────────

    /**
     * Pre-load semua LOCKED EndingBalance ke memory.
     * @return array<int, list<array{awal: string, akhir: string}>>
     */
    public function buildLockedEbMap(): array
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

    /**
     * Cek apakah tanggal tertentu masuk dalam periode EB LOCKED untuk klien ini.
     */
    public function isEbLocked(array $lockedEbMap, int $klienArId, string $tanggal): bool
    {
        foreach ($lockedEbMap[$klienArId] ?? [] as $range) {
            if ($tanggal >= $range['awal'] && $tanggal <= $range['akhir']) {
                return true;
            }
        }
        return false;
    }

    // ──────────────────────────────────────────────────────────────
    //  Private: create
    // ──────────────────────────────────────────────────────────────

    private function createInvoice(
        string $tipeInvoice,
        int    $klienArId,
        array  $headerData,
        array  $items,
    ): ProcessGroupResult {
        try {
            $klien     = KlienAr::with('perusahaan')->find($klienArId);
            $tanggal   = $headerData['tanggal_invoice'];
            $carryover = $this->service->getMonthlyCarryover($klienArId, $tanggal);
            $noInvoice = $tipeInvoice === 'B2B'
                ? $this->service->generateConsolidatedInvoiceNo($klien, $tanggal)
                : $this->service->generateNoInvoice($klien, $tanggal);

            $invoice = Invoice::create([
                'no_invoice'                 => $noInvoice,
                'tanggal_invoice'            => $tanggal,
                'tanggal_jatuh_tempo'        => $headerData['tanggal_jatuh_tempo'] ?? null,
                'klien_ar_id'                => $klienArId,
                // B2C: resto_id dari klien; B2B: null (consolidated)
                'resto_id'                   => $tipeInvoice === 'B2C' ? $klien->resto_id : null,
                'perusahaan_id'              => $klien->perusahaan_id,
                'karyawan_id'                => $this->service->resolveInvoiceKaryawanId(auth()->user(), $klien),
                'no_surat_jalan'             => $headerData['no_surat_jalan'] ?? null,
                'keterangan'                 => $headerData['keterangan'] ?? null,
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

            $this->insertItems($invoice, $items);
            $this->recomputeSubtotal($invoice);

            return ProcessGroupResult::inserted($invoice->fresh());
        } catch (\Throwable $e) {
            return ProcessGroupResult::failed('Gagal membuat invoice: ' . $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Private: update
    // ──────────────────────────────────────────────────────────────

    private function updateInvoice(Invoice $existingInvoice, array $items): ProcessGroupResult
    {
        try {
            InvoiceItem::where('invoice_id', $existingInvoice->id)->delete();
            $this->insertItems($existingInvoice, $items);
            $this->recomputeSubtotal($existingInvoice);

            $existingInvoice->refresh();

            DB::transaction(fn() => $this->service->recalculate($existingInvoice));
            $existingInvoice->refresh();

            if ((float) $existingInvoice->total_pembayaran > (float) $existingInvoice->subtotal
                && (float) $existingInvoice->subtotal > 0
            ) {
                $this->service->handleExcessPaymentAfterUpdate($existingInvoice);
            }

            $this->service->recalculateDraftEndingBalance($existingInvoice);

            return ProcessGroupResult::updated($existingInvoice->fresh());
        } catch (\Throwable $e) {
            return ProcessGroupResult::failed('Gagal mengupdate invoice: ' . $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Private: shared helpers
    // ──────────────────────────────────────────────────────────────

    private function insertItems(Invoice $invoice, array $items): void
    {
        foreach ($items as $item) {
            $qty    = (float) ($item['qty'] ?? 0);
            $harga  = (float) ($item['harga_satuan'] ?? 0);
            $invoice->items()->create([
                'barang_id'        => $item['barang_id'] ?? null,
                'kode_barang'      => $item['kode_barang'] ?? null,
                'nama_barang'      => $item['nama_barang'],
                'qty'              => $qty,
                'satuan'           => $item['satuan'] ?? null,
                'harga_satuan'     => $harga,
                'subtotal'         => $qty * $harga,
                'no_invoice_resto' => $item['no_invoice_resto'] ?? null,
                'kode_resto'       => $item['kode_resto'] ?? null,
                'nama_resto'       => $item['nama_resto'] ?? null,
            ]);
        }
    }

    private function recomputeSubtotal(Invoice $invoice): void
    {
        $subtotal     = (float) $invoice->items()->sum('subtotal');
        $totalTagihan = $subtotal + (float) $invoice->tagihan_periode_sebelumnya;
        $invoice->update([
            'subtotal'      => $subtotal,
            'total_tagihan' => $totalTagihan,
            'sisa_tagihan'  => $totalTagihan,
            'updated_by'    => auth()->id(),
        ]);
    }
}

// ──────────────────────────────────────────────────────────────
//  Value object hasil proses satu grup
// ──────────────────────────────────────────────────────────────

final class ProcessGroupResult
{
    private function __construct(
        public readonly string   $status,    // 'inserted' | 'updated' | 'skipped' | 'failed'
        public readonly ?Invoice $invoice,
        public readonly ?string  $error,
    ) {}

    public static function inserted(Invoice $invoice): self
    {
        return new self('inserted', $invoice, null);
    }

    public static function updated(Invoice $invoice): self
    {
        return new self('updated', $invoice, null);
    }

    public static function skipped(string $reason): self
    {
        return new self('skipped', null, $reason);
    }

    public static function failed(string $error): self
    {
        return new self('failed', null, $error);
    }

    public function isInserted(): bool { return $this->status === 'inserted'; }
    public function isUpdated(): bool  { return $this->status === 'updated'; }
    public function isSkipped(): bool  { return $this->status === 'skipped'; }
    public function isFailed(): bool   { return $this->status === 'failed'; }
}
