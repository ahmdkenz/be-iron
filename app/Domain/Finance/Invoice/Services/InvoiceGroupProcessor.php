<?php

namespace App\Domain\Finance\Invoice\Services;

use App\Models\EndingBalance;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\KlienAr;
use Carbon\Carbon;
use Illuminate\Support\Collection;
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
     * @param  ?array $existingInvoiceMap  Preloaded map dari InvoiceImportService::buildApplyExistingMap()
     *                              (key: "klienArId|Y-m-d"). Null berarti query per-grup seperti biasa
     *                              (dipakai caller lain di luar import chunk).
     * @param  ?array $klienMap  Preloaded map klien_ar_id => KlienAr (with perusahaan) dari
     *                              InvoiceImportService::buildApplyKlienMap(). Null berarti
     *                              query per-grup seperti biasa (dipakai caller lain di luar import chunk).
     * @param  ?array $carryoverMap  Preloaded map klien_ar_id => Collection<Invoice> dari
     *                              InvoiceImportService::buildApplyCarryoverMap(). Null berarti
     *                              query per-grup seperti biasa (dipakai caller lain di luar import chunk).
     * @param  ?array $cascadeCandidatesMap  Preloaded map klien_ar_id => Collection<Invoice> dari
     *                              InvoiceImportService::buildApplyCascadeCandidatesMap() (sudah
     *                              di-hydrate agar berbagi instance objek dengan $existingInvoiceMap).
     *                              Null berarti cascadeCarryoverToNext() fallback ke live-query
     *                              per-invoice seperti biasa (dipakai caller lain di luar import chunk).
     * @return ProcessGroupResult
     */
    public function processGroup(
        string $tipeInvoice,
        array $headerData,
        array $items,
        array $lockedEbMap,
        ?array $existingInvoiceMap = null,
        ?array $klienMap = null,
        ?array $carryoverMap = null,
        ?array $cascadeCandidatesMap = null,
    ): ProcessGroupResult {
        $klienArId = (int) $headerData['klien_ar_id'];
        $tanggal   = $headerData['tanggal_invoice'];

        if ($this->isEbLocked($lockedEbMap, $klienArId, $tanggal)) {
            return ProcessGroupResult::skipped('Periode sudah dikunci di Ending Balance');
        }

        $existingInvoice = $existingInvoiceMap !== null
            ? ($existingInvoiceMap[$klienArId . '|' . $tanggal] ?? null)
            : Invoice::where('klien_ar_id', $klienArId)
                ->whereDate('tanggal_invoice', $tanggal)
                ->where('is_opening_balance', false)
                ->first();

        if ($existingInvoice) {
            if ($existingInvoice->status === 'LUNAS') {
                return ProcessGroupResult::skipped("Invoice {$existingInvoice->no_invoice} sudah LUNAS");
            }

            $preloadedCascadeCandidates = $cascadeCandidatesMap !== null
                ? ($cascadeCandidatesMap[$klienArId] ?? collect())
                : null;

            return $this->updateInvoice($existingInvoice, $items, $preloadedCascadeCandidates);
        }

        return $this->createInvoice($tipeInvoice, $klienArId, $headerData, $items, $klienMap, $carryoverMap);
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

        if (empty($firstByKlien)) {
            return;
        }

        // Preload kandidat cascade SEMUA klien di batch ini sekali (mirror pola
        // OpeningBalanceImportService::createOpeningBalance() bypassApproval) — bukan
        // 1 query "next invoice" per klien di dalam cascadeCarryoverToNext(). Aman
        // dipanggil TANPA hydrasi (beda dari applySafeChunk()) karena tiap klien di sini
        // di-cascade TEPAT SEKALI per pemanggilan method ini (dedup $firstByKlien).
        $minMonthStart = collect($firstByKlien)
            ->map(fn(Invoice $inv) => Carbon::parse($inv->tanggal_invoice)->startOfMonth())
            ->min();

        $candidatesByKlien = Invoice::whereIn('klien_ar_id', array_keys($firstByKlien))
            ->where('tanggal_invoice', '>=', $minMonthStart->toDateString())
            ->orderBy('tanggal_invoice')
            ->orderBy('id')
            ->get()
            ->groupBy('klien_ar_id');

        foreach ($firstByKlien as $klienId => $firstInvoice) {
            try {
                $preloaded = $candidatesByKlien->get($klienId) ?? collect();
                DB::transaction(fn() => $this->service->propagateCarryover($firstInvoice, $preloaded));
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
        ?array $klienMap = null,
        ?array $carryoverMap = null,
    ): ProcessGroupResult {
        try {
            $klien     = $klienMap !== null
                ? ($klienMap[$klienArId] ?? KlienAr::with('perusahaan')->find($klienArId))
                : KlienAr::with('perusahaan')->find($klienArId);
            $tanggal   = $headerData['tanggal_invoice'];
            $preloadedInvoices = $carryoverMap !== null ? ($carryoverMap[$klienArId] ?? collect()) : null;
            $carryover = $this->service->getMonthlyCarryover($klienArId, $tanggal, $preloadedInvoices);
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

            $subtotal = $this->insertItems($invoice, $items);
            $this->recomputeSubtotal($invoice, $subtotal);

            // fresh() dihapus: recomputeSubtotal() sudah men-set subtotal/total_tagihan/
            // sisa_tagihan/updated_by di objek in-memory yang sama via update() Eloquent;
            // tidak ada yang menulis balik ke baris ini di antara create() dan return.
            return ProcessGroupResult::inserted($invoice);
        } catch (\Throwable $e) {
            return ProcessGroupResult::failed('Gagal membuat invoice: ' . $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Private: update
    // ──────────────────────────────────────────────────────────────

    private function updateInvoice(Invoice $existingInvoice, array $items, ?Collection $preloadedCascadeCandidates = null): ProcessGroupResult
    {
        try {
            // insertItems()+recomputeSubtotal()+recalculate() dibungkus 1 transaction:
            // recomputeSubtotal() menulis sisa_tagihan mentah (belum memperhitungkan
            // total_pembayaran) sebagai langkah antara sebelum recalculate() memperbaikinya
            // — kalau proses gagal di tengah tanpa transaction, invoice bisa nyangkut
            // dengan sisa_tagihan yang mengabaikan pembayaran yang sudah ada.
            DB::transaction(function () use ($existingInvoice, $items, $preloadedCascadeCandidates) {
                InvoiceItem::where('invoice_id', $existingInvoice->id)->delete();
                $subtotal = $this->insertItems($existingInvoice, $items);
                $this->recomputeSubtotal($existingInvoice, $subtotal);

                // refresh() dihapus: recomputeSubtotal() sudah update() objek ini in-memory;
                // recalculate() di bawah membaca field yang sama objek ini (subtotal, carryover,
                // klien_ar_id, tanggal_invoice) — tidak ada yang stale.
                $this->service->recalculate($existingInvoice, $preloadedCascadeCandidates);
                // refresh() dihapus: recalculate() memutasi $existingInvoice in-place via
                // update() miliknya sendiri (referensi objek yang sama); cascade di dalamnya
                // hanya menulis invoice LAIN (ke depan), tidak pernah menulis balik baris ini.
            });

            // subtotal > 0 SENGAJA tidak lagi jadi syarat: invoice yang di-reimport
            // sampai subtotal-nya persis 0 (mis. semua baris item hilang dari file
            // sumber) tetap bisa overpaid kalau sudah punya pembayaran besar dari
            // rekonsiliasi bank — kelebihannya tetap harus dicek untuk dijadikan PDM.
            if ((float) $existingInvoice->total_pembayaran > (float) $existingInvoice->subtotal) {
                $this->service->handleExcessPaymentAfterUpdate($existingInvoice);
            }

            $this->service->recalculateDraftEndingBalance($existingInvoice);

            // fresh() dihapus (lihat alasan di atas). Catatan: header-diff SAFE_UPDATE
            // (tanggal_jatuh_tempo/no_surat_jalan/keterangan) ditulis applyGroup() lewat
            // Invoice::whereKey()->update() TERPISAH sebelum processGroup() dipanggil —
            // field itu sudah stale di objek ini sejak sebelum method ini berjalan (tidak
            // berubah oleh perubahan ini). Aman karena ProcessGroupResult->invoice hanya
            // dibaca untuk id/no_invoice oleh applyGroup(), tidak untuk field tsb.
            return ProcessGroupResult::updated($existingInvoice);
        } catch (\Throwable $e) {
            return ProcessGroupResult::failed('Gagal mengupdate invoice: ' . $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Private: shared helpers
    // ──────────────────────────────────────────────────────────────

    /** @return float Total subtotal item yang baru diinsert (sum per-item, di-round 2dp sebelum diakumulasi — meniru rounding storage DECIMAL(15,2) MySQL — bukan raw product). */
    private function insertItems(Invoice $invoice, array $items): float
    {
        if (empty($items)) {
            return 0.0;
        }

        $now  = now();
        $subtotalSum = 0.0;
        $rows = array_map(function (array $item) use ($invoice, $now, &$subtotalSum) {
            $qty   = (float) ($item['qty'] ?? 0);
            $harga = (float) ($item['harga_satuan'] ?? 0);
            $itemSubtotal = $qty * $harga;
            $subtotalSum += round($itemSubtotal, 2);

            return [
                'invoice_id'       => $invoice->id,
                'barang_id'        => $item['barang_id'] ?? null,
                'kode_barang'      => $item['kode_barang'] ?? null,
                'nama_barang'      => $item['nama_barang'],
                'qty'              => $qty,
                'satuan'           => $item['satuan'] ?? null,
                'harga_satuan'     => $harga,
                'subtotal'         => $itemSubtotal,
                'no_invoice_resto' => $item['no_invoice_resto'] ?? null,
                'kode_resto'       => $item['kode_resto'] ?? null,
                'nama_resto'       => $item['nama_resto'] ?? null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }, $items);

        InvoiceItem::insert($rows);

        return round($subtotalSum, 2);
    }

    private function recomputeSubtotal(Invoice $invoice, float $subtotal): void
    {
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
