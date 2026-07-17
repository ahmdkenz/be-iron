<?php

namespace App\Domain\Finance\ApShz360Sync\Services;

use App\Domain\Finance\ApShz360Sync\Support\VendorNameMatcher;
use App\Domain\Finance\TagihanAp\DTO\TagihanApDTO;
use App\Domain\Finance\TagihanAp\Services\TagihanApService;
use App\Domain\Finance\VendorAp\DTO\VendorApDTO;
use App\Domain\Finance\VendorAp\Services\VendorApService;
use App\Models\ApImportTagihanLink;
use App\Models\ApShz360PoImport;
use App\Models\ApShz360ReceiptImport;
use App\Models\ApSourceVendorMap;
use App\Models\TagihanAp;
use App\Models\VendorAp;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ApShz360ImportService
{
    public function __construct(
        private readonly TagihanApService $tagihanApService,
        private readonly VendorApService $vendorApService,
    ) {}

    /**
     * Staging di-list per penerimaan barang (receipt) karena itu unit yang
     * bisa dikonversi jadi 1 tagihan, bukan per PO (1 PO bisa punya beberapa
     * penerimaan bertahap).
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = ApShz360ReceiptImport::with(['poImport.vendorAp', 'tagihanLink'])->latest('id');

        if (! empty($filters['import_status'])) {
            $query->where('import_status', $filters['import_status']);
        }

        if (! empty($filters['kode_po'])) {
            $keyword = $filters['kode_po'];
            $query->whereHas('poImport', fn ($q) => $q->where('kode_po', 'like', "%{$keyword}%"));
        }

        if (! empty($filters['kode_receipt'])) {
            $query->where('kode_receipt', 'like', '%' . $filters['kode_receipt'] . '%');
        }

        if (! empty($filters['need_mapping'])) {
            $query->where(fn ($q) => $q->whereDoesntHave('poImport')
                ->orWhereHas('poImport', fn ($qq) => $qq->whereNull('vendor_ap_id')));
        }

        return $query->paginate((int) ($filters['per_page'] ?? 20));
    }

    public function findOrFail(int $id): ApShz360ReceiptImport
    {
        $receipt = ApShz360ReceiptImport::with(['poImport.items', 'items', 'tagihanLink.tagihanAp'])->find($id);
        abort_if(! $receipt, 404, 'Data staging tidak ditemukan');

        return $receipt;
    }

    public function mapVendor(ApShz360ReceiptImport $receipt, int $vendorApId): ApShz360ReceiptImport
    {
        abort_if(! $receipt->po_import_id, 422, 'Data ini tidak terhubung ke PO manapun di SHZ360');

        $poImport = $receipt->poImport;
        $vendor = VendorAp::findOrFail($vendorApId);

        DB::transaction(function () use ($poImport, $vendor) {
            $poImport->update([
                'vendor_ap_id' => $vendor->id,
                'import_status' => $poImport->import_status === 'NEED_MAPPING' ? 'READY_FOR_AP' : $poImport->import_status,
            ]);

            $normalizedName = null;

            if ($poImport->source_supplier_id) {
                ApSourceVendorMap::where('source_system', 'SHZ360')
                    ->where('source_supplier_id', $poImport->source_supplier_id)
                    ->update(['vendor_ap_id' => $vendor->id, 'status' => 'MAPPED']);

                $normalizedName = VendorNameMatcher::normalize(
                    ApSourceVendorMap::where('source_system', 'SHZ360')
                        ->where('source_supplier_id', $poImport->source_supplier_id)
                        ->value('source_supplier_name')
                );
            }

            // Penerimaan lain di bawah PO yang sama & masih baru ikut siap dikonversi.
            ApShz360ReceiptImport::where('po_import_id', $poImport->id)
                ->where('import_status', 'NEW')
                ->update(['import_status' => 'READY_FOR_AP']);

            // Baris lain (PO/supplier_id berbeda) dengan nama supplier SHZ360 yang sama
            // ikut terselesaikan, supaya tidak perlu dipetakan berulang per supplier_id.
            if ($normalizedName) {
                $this->cascadeVendorByName($normalizedName, $vendor->id);
            }
        });

        return $this->findOrFail($receipt->id);
    }

    /**
     * Terapkan vendor_ap_id ke semua ApSourceVendorMap/ApShz360PoImport/ApShz360ReceiptImport
     * lain yang masih belum dipetakan tapi nama supplier SHZ360-nya (ternormalisasi) sama
     * dengan yang baru saja dipetakan. Dipakai oleh mapVendor() dan command backfill retroaktif.
     */
    public function cascadeVendorByName(string $normalizedName, int $vendorApId): array
    {
        $maps = ApSourceVendorMap::where('source_system', 'SHZ360')
            ->whereNull('vendor_ap_id')
            ->whereRaw('UPPER(TRIM(source_supplier_name)) = ?', [$normalizedName])
            ->get();

        if ($maps->isEmpty()) {
            return ['maps' => 0, 'po' => 0, 'receipts' => 0];
        }

        foreach ($maps as $map) {
            $map->update(['vendor_ap_id' => $vendorApId, 'status' => 'MAPPED']);
        }

        $poImports = ApShz360PoImport::whereIn('source_supplier_id', $maps->pluck('source_supplier_id'))
            ->whereNull('vendor_ap_id')
            ->get();

        $receiptCount = 0;

        foreach ($poImports as $po) {
            $po->update([
                'vendor_ap_id' => $vendorApId,
                'import_status' => $po->import_status === 'NEED_MAPPING' ? 'READY_FOR_AP' : $po->import_status,
            ]);

            $receiptCount += ApShz360ReceiptImport::where('po_import_id', $po->id)
                ->where('import_status', 'NEW')
                ->update(['import_status' => 'READY_FOR_AP']);
        }

        return ['maps' => $maps->count(), 'po' => $poImports->count(), 'receipts' => $receiptCount];
    }

    /**
     * Buat Vendor AP baru dari data supplier SHZ360 yang sudah ke-staging (nama/NPWP/bank
     * auto-fill dari raw_payload), lalu langsung petakan. PIC AP tetap wajib dipilih
     * eksplisit oleh admin — SHZ360 tidak punya data itu, jadi tidak ada default
     * tersembunyi (konsisten dengan keputusan desain fitur Import Master Data AP sebelumnya).
     */
    public function createVendorFromSupplier(ApShz360ReceiptImport $receipt, array $payload): ApShz360ReceiptImport
    {
        abort_if(! $receipt->po_import_id, 422, 'Data ini tidak terhubung ke PO manapun di SHZ360');

        $poImport = $receipt->poImport;
        abort_if($poImport->vendor_ap_id, 422, 'PO ini sudah punya vendor terpetakan');

        $supplier = $poImport->raw_payload['supplier'] ?? null;
        abort_if(empty($supplier['nama_supplier']), 422, 'Data supplier dari SHZ360 tidak lengkap, tidak bisa membuat vendor otomatis');

        $kodeVendor = trim($payload['kode_vendor'] ?? ($supplier['kode_supplier'] ?? ''));
        abort_if($kodeVendor === '', 422, 'Kode Supplier wajib diisi untuk membuat vendor baru dari data SHZ360.');

        $dto = new VendorApDTO(
            kode_vendor: $kodeVendor,
            nama_vendor: $supplier['nama_supplier'],
            no_npwp: $supplier['npwp'] ?? null,
            status_pkp: false,
            bank_nama: $supplier['bank_nama'] ?? null,
            bank_no_rekening: $supplier['bank_no_rekening'] ?? null,
            bank_atas_nama: $supplier['bank_atas_nama'] ?? null,
            karyawan_ap_id: (int) $payload['karyawan_ap_id'],
        );

        return DB::transaction(function () use ($receipt, $dto) {
            $vendor = $this->vendorApService->create($dto);

            return $this->mapVendor($receipt, $vendor->id);
        });
    }

    public function ignore(ApShz360ReceiptImport $receipt): ApShz360ReceiptImport
    {
        abort_if($receipt->import_status === 'CONVERTED', 422, 'Data yang sudah dikonversi jadi tagihan tidak bisa di-ignore');

        $receipt->update(['import_status' => 'IGNORED']);

        return $receipt;
    }

    public function convertToTagihan(ApShz360ReceiptImport $receipt, array $payload): TagihanAp
    {
        abort_if($receipt->tagihanLink()->exists(), 422, 'Data ini sudah pernah dikonversi jadi tagihan');

        $poImport = $receipt->poImport;
        abort_if(! $poImport || ! $poImport->vendor_ap_id, 422, 'Vendor belum dipetakan untuk PO terkait — petakan vendor dulu sebelum convert');

        $items = $receipt->items->map(fn ($item) => [
            'barang_id' => null,
            'kode_barang' => $item->kode_barang,
            'nama_barang' => $item->nama_barang,
            'qty' => (float) $item->qty_diterima,
            'satuan' => $item->satuan,
            'harga_satuan' => (float) $item->harga,
            'keterangan' => 'Import SHZ360 — ' . $receipt->kode_receipt,
        ])->values()->all();

        abort_if(count($items) === 0, 422, 'Penerimaan ini tidak punya item, tidak bisa dikonversi jadi tagihan');

        $dto = new TagihanApDTO(
            no_tagihan: $payload['no_tagihan'] ?? null,
            no_invoice_vendor: $payload['no_invoice_vendor'] ?? $receipt->no_invoice,
            vendor_ap_id: (int) $poImport->vendor_ap_id,
            tanggal_tagihan: $payload['tanggal_tagihan'] ?? now()->toDateString(),
            tanggal_jatuh_tempo: $payload['tanggal_jatuh_tempo'] ?? null,
            no_po: $poImport->kode_po,
            no_terima_barang: $receipt->kode_receipt,
            ppn_masukan: (float) ($payload['ppn_masukan'] ?? 0),
            pph23: (float) ($payload['pph23'] ?? 0),
            keterangan: $payload['keterangan'] ?? ('Dibuat otomatis dari staging SHZ360 (' . $receipt->kode_receipt . ')'),
            items: $items,
        );

        return DB::transaction(function () use ($dto, $receipt, $poImport) {
            $tagihan = $this->tagihanApService->create($dto);

            $tagihan->update([
                'source_system' => 'SHZ360',
                'ap_shz360_po_import_id' => $poImport->id,
                'ap_shz360_receipt_import_id' => $receipt->id,
            ]);

            ApImportTagihanLink::create([
                'tagihan_ap_id' => $tagihan->id,
                'po_import_id' => $poImport->id,
                'receipt_import_id' => $receipt->id,
                'amount_allocated' => $tagihan->total_tagihan,
                'created_by' => auth()->id(),
            ]);

            $receipt->update(['import_status' => 'CONVERTED']);

            return $tagihan->fresh();
        });
    }
}
