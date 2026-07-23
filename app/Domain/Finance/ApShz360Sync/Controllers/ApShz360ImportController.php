<?php

namespace App\Domain\Finance\ApShz360Sync\Controllers;

use App\Domain\Finance\ApShz360Sync\Jobs\ApShz360SyncJob;
use App\Domain\Finance\ApShz360Sync\Services\ApShz360ImportService;
use App\Domain\Finance\ApShz360Sync\Support\VendorNameMatcher;
use App\Http\Controllers\Controller;
use App\Models\ApShz360ReceiptImport;
use App\Models\ApShz360SyncError;
use App\Models\ApShz360SyncRun;
use App\Models\VendorAp;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApShz360ImportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ApShz360ImportService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView();

        $filters = $request->only(['import_status', 'kode_po', 'kode_receipt', 'need_mapping', 'per_page']);
        $list = $this->service->paginate($filters);

        return $this->paginatedResponse($list->through(fn (ApShz360ReceiptImport $r) => $this->toArray($r)));
    }

    public function summary(): JsonResponse
    {
        $this->authorizeView();

        return $this->successResponse($this->service->summary());
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeView();

        $receipt = $this->service->findOrFail($id);

        return $this->successResponse($this->toArray($receipt, detail: true));
    }

    public function mapVendor(Request $request, int $id): JsonResponse
    {
        $this->authorizeOperate();

        $payload = $request->validate([
            'vendor_ap_id' => ['required', 'integer', 'exists:tb_vendor_ap,id'],
        ]);

        $receipt = $this->service->findOrFail($id);
        $updated = $this->service->mapVendor($receipt, (int) $payload['vendor_ap_id']);

        return $this->successResponse($this->toArray($updated, detail: true), 'Vendor berhasil dipetakan');
    }

    public function createVendor(Request $request, int $id): JsonResponse
    {
        $this->authorizeOperate();

        $payload = $request->validate([
            'karyawan_ap_id' => ['required', 'integer', 'exists:tb_karyawan,id'],
            'kode_vendor'    => ['nullable', 'string', 'max:20'],
        ]);

        $receipt = $this->service->findOrFail($id);
        $updated = $this->service->createVendorFromSupplier($receipt, $payload);

        return $this->createdResponse($this->toArray($updated, detail: true), 'Vendor baru berhasil dibuat dan dipetakan');
    }

    public function convertToTagihan(Request $request, int $id): JsonResponse
    {
        $this->authorizeOperate();

        $payload = $request->validate([
            'no_tagihan' => ['nullable', 'string', 'max:50'],
            'no_invoice_vendor' => ['nullable', 'string'],
            'tanggal_tagihan' => ['nullable', 'date'],
            'tanggal_jatuh_tempo' => ['nullable', 'date'],
            'ppn_masukan' => ['nullable', 'numeric', 'min:0'],
            'pph23' => ['nullable', 'numeric', 'min:0'],
            'keterangan' => ['nullable', 'string'],
        ]);

        $receipt = $this->service->findOrFail($id);
        $tagihan = $this->service->convertToTagihan($receipt, $payload);

        return $this->createdResponse([
            'tagihan_ap_id' => $tagihan->id,
            'no_tagihan' => $tagihan->no_tagihan,
        ], 'Tagihan AP berhasil dibuat dari staging SHZ360');
    }

    public function ignore(int $id): JsonResponse
    {
        $this->authorizeOperate();

        $receipt = $this->service->findOrFail($id);
        $updated = $this->service->ignore($receipt);

        return $this->successResponse($this->toArray($updated, detail: true), 'Data staging diabaikan');
    }

    public function retrySync(): JsonResponse
    {
        $this->authorizeOperate();

        ApShz360SyncJob::dispatch();

        return $this->successResponse(null, 'Sync sedang diproses di latar belakang.', 202);
    }

    public function fullResync(): JsonResponse
    {
        $this->authorizeOperate();

        ApShz360SyncJob::dispatch(forceFullResync: true);

        return $this->successResponse(null, 'Full resync sedang diproses di latar belakang.', 202);
    }

    public function lastSyncRun(): JsonResponse
    {
        $this->authorizeView();

        $run = ApShz360SyncRun::latest('started_at')->first();
        abort_if(! $run, 404, 'Belum pernah ada sync yang berjalan');

        return $this->successResponse($this->syncRunToArray($run));
    }

    public function syncErrors(Request $request): JsonResponse
    {
        $this->authorizeView();

        $limit = (int) $request->query('limit', 10);
        $limit = $limit > 0 && $limit <= 50 ? $limit : 10;

        $errors = ApShz360SyncError::with('syncRun:id,started_at,status')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (ApShz360SyncError $e) => [
                'id' => $e->id,
                'sync_run_id' => $e->sync_run_id,
                'run_started_at' => $e->syncRun?->started_at?->toIso8601String(),
                'source_type' => $e->source_type,
                'source_id' => $e->source_id,
                'message' => $e->message,
            ]);

        return $this->successResponse($errors);
    }

    private function syncRunToArray(ApShz360SyncRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'po_fetched' => $run->po_fetched,
            'po_upserted' => $run->po_upserted,
            'receipt_fetched' => $run->receipt_fetched,
            'receipt_upserted' => $run->receipt_upserted,
            'message' => $run->message,
            'error_count' => $run->errors()->count(),
        ];
    }

    private function toArray(ApShz360ReceiptImport $receipt, bool $detail = false): array
    {
        $poImport = $receipt->poImport;
        // Fallback ke raw_payload milik receipt sendiri kalau PO belum (atau belum
        // lagi) ter-staging lokal — data ini sudah dikirim source bersama receipt-nya,
        // jadi Kode PO & Supplier tetap bisa tampil tanpa menunggu PO ke-link.
        $rawSupplier = $receipt->raw_payload['supplier'] ?? null;

        $data = [
            'id' => $receipt->id,
            'kode_receipt' => $receipt->kode_receipt,
            'tanggal_receipt' => $receipt->tanggal_receipt?->format('Y-m-d'),
            'no_invoice' => $receipt->no_invoice,
            'no_surat_jalan' => $receipt->no_surat_jalan,
            'no_faktur_pajak' => $receipt->no_faktur_pajak,
            'import_status' => $receipt->import_status,
            'kode_po' => $poImport?->kode_po ?? $receipt->raw_payload['kode_po'] ?? null,
            'vendor_ap_id' => $poImport?->vendor_ap_id,
            'vendor_nama' => $poImport?->vendorAp?->nama_vendor,
            'source_supplier_name' => $poImport?->raw_payload['supplier']['nama_supplier']
                ?? $rawSupplier['nama_supplier'] ?? null,
            'source_supplier' => $this->extractSupplier($poImport?->raw_payload['supplier'] ?? $rawSupplier),
            'need_mapping' => ! $poImport || ! $poImport->vendor_ap_id,
            'total_diterima' => (float) $receipt->items()->sum('subtotal'),
            'has_reject_items' => (bool) $receipt->has_reject_items,
        ];

        if ($data['need_mapping']) {
            $data['suggested_vendor_ap'] = $this->findSuggestedVendor($data['source_supplier_name']);
        }

        if ($detail) {
            $data['tanggal_po'] = $poImport?->tanggal_po?->format('Y-m-d');
            $data['status_po'] = $poImport?->status_po;
            $data['po_subtotal'] = (float) ($poImport?->subtotal ?? 0);
            $data['po_ongkir'] = (float) ($poImport?->ongkir ?? 0);
            $data['po_diskon'] = (float) ($poImport?->diskon ?? 0);
            $data['po_grand_total'] = (float) ($poImport?->grand_total ?? 0);

            $data['items'] = $receipt->items->map(fn ($item) => [
                'kode_barang' => $item->kode_barang,
                'nama_barang' => $item->nama_barang,
                'satuan' => $item->satuan,
                'status_detail_terima_po' => $item->status_detail_terima_po,
                'qty_po' => $item->poImportItem?->qty_po !== null ? (float) $item->poImportItem->qty_po : null,
                'qty_diterima' => (float) $item->qty_diterima,
                'qty_tolak' => (float) $item->qty_tolak,
                'keterangan_tolak' => $item->keterangan_tolak,
                'harga' => (float) $item->harga,
                'ppn' => $item->poImportItem?->ppn !== null ? (float) $item->poImportItem->ppn : null,
                'subtotal' => (float) $item->subtotal,
            ])->values();

            $data['po_items'] = $poImport?->items->map(fn ($item) => [
                'kode_barang' => $item->kode_barang,
                'nama_barang' => $item->nama_barang,
                'satuan' => $item->satuan,
                'qty_po' => (float) $item->qty_po,
                'harga' => (float) $item->harga,
                'subtotal' => (float) $item->subtotal,
            ])->values();

            $data['tagihan_ap_id'] = $receipt->tagihanLink?->tagihan_ap_id;
        }

        return $data;
    }

    private function findSuggestedVendor(?string $sourceSupplierName): ?array
    {
        $normalized = VendorNameMatcher::normalize($sourceSupplierName);

        if (! $normalized) {
            return null;
        }

        $vendor = VendorAp::with('karyawanAp')
            ->whereRaw('UPPER(TRIM(nama_vendor)) = ?', [$normalized])
            ->first();

        if (! $vendor) {
            return null;
        }

        return [
            'id' => $vendor->id,
            'nama_vendor' => $vendor->nama_vendor,
            'kode_vendor' => $vendor->kode_vendor,
            'karyawan_ap_nama' => $vendor->karyawanAp?->nama_karyawan,
        ];
    }

    private function extractSupplier(?array $supplier): ?array
    {
        if (! $supplier) {
            return null;
        }

        return [
            'kode_supplier' => $supplier['kode_supplier'] ?? null,
            'nama_supplier' => $supplier['nama_supplier'] ?? null,
            'npwp' => $supplier['npwp'] ?? null,
            'bank_nama' => $supplier['bank_nama'] ?? null,
            'bank_no_rekening' => $supplier['bank_no_rekening'] ?? null,
            'bank_atas_nama' => $supplier['bank_atas_nama'] ?? null,
        ];
    }

    private function authorizeView(): void
    {
        abort_if(! RoleHelper::canViewApShz360Import(auth()->user()), 403, 'Tidak memiliki akses ke data staging SHZ360');
    }

    private function authorizeOperate(): void
    {
        abort_if(! RoleHelper::canOperateApShz360Import(auth()->user()), 403, 'Tidak memiliki akses untuk mengelola staging SHZ360');
    }
}
