<?php

namespace App\Domain\Finance\Invoice\Controllers;

use App\Domain\Finance\Invoice\DTO\InvoiceDTO;
use App\Domain\Finance\Invoice\Requests\StoreInvoiceRequest;
use App\Domain\Finance\Invoice\Requests\UpdateInvoiceRequest;
use App\Domain\Finance\Invoice\Resources\InvoiceItemResource;
use App\Domain\Finance\Invoice\Resources\InvoiceListResource;
use App\Domain\Finance\Invoice\Resources\InvoiceResource;
use App\Domain\Finance\Invoice\Services\InvoicePrintItemAggregator;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\KlienAr;
use App\Models\PembayaranArItem;
use App\Models\Resto;
use Carbon\Carbon;
use App\Support\Helpers\ArFilterScope;
use App\Support\Helpers\BulkPrintTokenCodec;
use App\Support\Helpers\RoleHelper;
use App\Support\Helpers\SignatureBarcodeHelper;
use App\Support\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly InvoiceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user    = auth()->user();
        $filters = $request->only([
            'search', 'status', 'klien_ar_id', 'karyawan_id',
            'tanggal_dari', 'tanggal_sampai', 'segment', 'per_page',
        ]);
        $filters['is_opening_balance'] = false;
        ArFilterScope::apply($filters, $user);

        $list      = $this->service->paginate($filters);
        $lockedIds = $this->batchLoadEbLockedIds($list->getCollection());

        return $this->paginatedResponse(
            $list->through(fn($inv) => new InvoiceListResource($inv, $lockedIds))
        );
    }

    /**
     * Tolak akses kalau user AR murni (PIC AR) meminta data Client yang bukan
     * miliknya. Admin/Manager/Supervisor tetap punya akses global.
     */
    private function authorizeKlienArOwnership(int $klienArId): void
    {
        $picArKaryawanId = RoleHelper::picArKaryawanIdFor(auth()->user());
        if ($picArKaryawanId === null) {
            return;
        }

        $klien = KlienAr::withTrashed()->find($klienArId);

        abort_if(
            !$klien || (int) $klien->karyawan_ar_id !== $picArKaryawanId,
            403,
            'Anda hanya dapat mengakses data Client yang ditugaskan kepada Anda'
        );
    }

    /**
     * Scoping akses invoice per-ID, mencerminkan aturan yang sama dengan
     * ArFilterScope::apply() yang dipakai daftar invoice (index/summary/export):
     * AR murni dibatasi ke Client yang ditugaskan kepadanya, user non-global-AR
     * lain (yang punya data karyawan) dibatasi ke perusahaan miliknya sendiri,
     * dan ADMIN/MANAGER/SUPERVISOR tetap bebas akses. Dipakai untuk menutup
     * celah endpoint detail invoice (show/items/pembayaran/approvalLogs/koreksi/
     * print) yang sebelumnya bisa diakses siapa saja lewat ID langsung.
     */
    private function authorizeInvoiceAccess(Invoice $invoice): void
    {
        $user = auth()->user();
        $user->loadMissing('karyawan');

        if (!$user->karyawan || RoleHelper::hasGlobalArAccess($user)) {
            return;
        }

        if (RoleHelper::isArOnly($user)) {
            $klienKaryawanArId = $invoice->klien_ar_id
                ? KlienAr::withTrashed()->whereKey($invoice->klien_ar_id)->value('karyawan_ar_id')
                : null;

            abort_if(
                $klienKaryawanArId === null || (int) $klienKaryawanArId !== $user->karyawan->id,
                403,
                'Anda hanya dapat mengakses data invoice Client yang ditugaskan kepada Anda'
            );

            return;
        }

        abort_if(
            (int) $invoice->perusahaan_id !== (int) $user->karyawan->perusahaan_id,
            403,
            'Anda tidak memiliki akses ke invoice ini'
        );
    }

    /**
     * Batch-load id invoice yang berada dalam periode ending balance LOCKED,
     * satu query untuk seluruh halaman (hindari N+1 per baris).
     */
    private function batchLoadEbLockedIds(\Illuminate\Support\Collection $invoices): array
    {
        $ids = $invoices->pluck('id')->all();
        if (empty($ids)) {
            return [];
        }

        return DB::table('tb_invoice as i')
            ->join('tb_ending_balance as eb', function ($join) {
                $join->on('i.klien_ar_id', '=', 'eb.klien_ar_id')
                    ->whereColumn('i.tanggal_invoice', '>=', 'eb.periode_awal')
                    ->whereColumn('i.tanggal_invoice', '<=', 'eb.periode_akhir');
            })
            ->where('eb.status', 'LOCKED')
            ->whereIn('i.id', $ids)
            ->pluck('i.id')
            ->all();
    }

    public function summary(Request $request): JsonResponse
    {
        $user    = auth()->user();
        $filters = $request->only([
            'search', 'status', 'klien_ar_id', 'karyawan_id',
            'tanggal_dari', 'tanggal_sampai', 'segment',
        ]);
        $filters['is_opening_balance'] = false;
        ArFilterScope::apply($filters, $user);

        return $this->successResponse($this->service->getSummary($filters));
    }

    public function carryover(Request $request): JsonResponse
    {
        $request->validate([
            'klien_ar_id'     => ['required', 'integer', 'exists:tb_klien_ar,id'],
            'tanggal_invoice' => ['nullable', 'date'],
        ]);

        $klienArId = (int) $request->klien_ar_id;
        $tanggal   = $request->tanggal_invoice;

        $carryover = $tanggal
            ? $this->service->getMonthlyCarryover($klienArId, $tanggal)
            : $this->service->getCarryover($klienArId);

        return $this->successResponse(['carryover' => $carryover]);
    }

    public function outstanding(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'klien_ar_id' => ['required', 'integer', 'exists:tb_klien_ar,id'],
            'tanggal'     => ['nullable', 'date'],
        ]);

        $this->authorizeKlienArOwnership((int) $validated['klien_ar_id']);

        $query = \App\Models\Invoice::with('items.barang')
            ->where('klien_ar_id', $validated['klien_ar_id'])
            ->whereIn('status', ['TERKIRIM', 'SEBAGIAN'])
            ->where('is_opening_balance', false);

        if (!empty($validated['tanggal'])) {
            $batasAwal = Carbon::parse($validated['tanggal'])->startOfMonth()->toDateString();
            $query->where('tanggal_invoice', '<', $batasAwal);
        }

        $invoices = $query
            ->orderBy('tanggal_invoice')
            ->orderBy('id')
            ->get();

        // Fallback: item lama bisa punya barang_id kosong tapi kode_barang valid.
        // Cari master barang berdasarkan kode_barang agar tetap tersambung.
        $kodeBarangTanpaId = $invoices->flatMap(fn($inv) => $inv->items)
            ->whereNull('barang_id')
            ->pluck('kode_barang')
            ->filter()
            ->unique()
            ->values();

        $barangByKode = $kodeBarangTanpaId->isNotEmpty()
            ? \App\Models\Barang::whereIn('kode_barang', $kodeBarangTanpaId)->get()->keyBy('kode_barang')
            : collect();

        $invoices = $invoices->map(fn($inv) => [
                'id'              => $inv->id,
                'no_invoice'      => $inv->no_invoice,
                'tanggal_invoice' => $inv->tanggal_invoice?->toDateString(),
                'subtotal'        => (float) $inv->subtotal,
                'total_tagihan'   => (float) $inv->total_tagihan,
                'sisa_tagihan'    => max(0.0, (float) $inv->subtotal - (float) $inv->total_pembayaran - (float) $inv->total_penyesuaian),
                'status'          => $inv->status,
                'keterangan'      => $inv->keterangan,
                'items'           => $inv->items->map(function ($item) use ($barangByKode) {
                    $fallbackBarang = !$item->barang_id && $item->kode_barang
                        ? $barangByKode->get($item->kode_barang)
                        : null;

                    return [
                        'barang_id'    => $item->barang_id ?? $fallbackBarang?->id,
                        'kode_barang'  => $item->kode_barang ?? $item->barang?->kode_barang ?? '',
                        'nama_barang'  => $item->nama_barang,
                        'qty'          => (float) $item->qty,
                        'satuan'       => $item->satuan ?? 'pcs',
                        'harga_satuan' => (float) $item->harga_satuan,
                        'subtotal'     => (float) $item->subtotal,
                        'keterangan'   => $item->keterangan ?? '',
                    ];
                })->values()->all(),
            ]);

        return $this->successResponse($invoices);
    }

    public function settleableOriginals(int $id): JsonResponse
    {
        $ob = $this->service->findOrFail($id);
        abort_if(!$ob->is_opening_balance, 422, 'Invoice ini bukan Opening Balance.');

        return $this->successResponse($this->service->getSettleableOriginals($ob));
    }

    public function previewNo(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'klien_ar_id' => ['required', 'integer', 'exists:tb_klien_ar,id'],
            'tanggal'     => ['required', 'date'],
        ]);

        $klien = KlienAr::findOrFail((int) $payload['klien_ar_id']);

        return $this->successResponse([
            'no_invoice' => $this->service->generateNoInvoice($klien, $payload['tanggal']),
        ]);
    }

    public function previewConsolidatedNo(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'klien_ar_id' => ['required', 'integer', 'exists:tb_klien_ar,id'],
            'tanggal'     => ['sometimes', 'date'],
        ]);

        $klien = KlienAr::with('perusahaan')->findOrFail((int) $payload['klien_ar_id']);

        return $this->successResponse([
            'no_invoice' => $this->service->generateConsolidatedInvoiceNo($klien, $payload['tanggal'] ?? null),
        ]);
    }

    public function rekapKlien(Request $request): JsonResponse
    {
        $user    = auth()->user();
        $request->validate([
            'klien_ar_id'    => ['nullable', 'integer', 'exists:tb_klien_ar,id'],
            'periode_bulan'  => ['nullable', 'integer', 'between:1,12'],
            'periode_tahun'  => ['nullable', 'integer', 'between:2000,2100'],
            'segment'        => ['nullable', 'in:B2B,B2C,ALL'],
            'page'           => ['nullable', 'integer', 'min:1'],
            'per_page'       => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filters = $request->only(['klien_ar_id', 'periode_bulan', 'periode_tahun', 'segment']);
        ArFilterScope::apply($filters, $user);

        $rows = $this->service->getRekapKlien($filters);

        // Tanpa per_page: kembalikan array polos seperti sebelumnya (dipakai
        // ExportData yang butuh seluruh baris tanpa dipotong).
        if (!$perPage = $request->integer('per_page')) {
            return $this->successResponse($rows);
        }

        $summary = [
            'total_klien'      => count($rows),
            'total_tagihan'    => array_sum(array_column($rows, 'total_tagihan')),
            'total_pembayaran' => array_sum(array_column($rows, 'total_pembayaran')),
            'total_sisa'       => array_sum(array_column($rows, 'sisa_tagihan')),
        ];

        ['items' => $pagedRows, 'meta' => $meta] = $this->paginateArray($rows, $request->integer('page', 1), $perPage);

        return $this->successResponse([
            'summary' => $summary,
            'rows'    => $pagedRows,
            'meta'    => $meta,
        ]);
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->service->create(InvoiceDTO::fromRequest($request->validated()));

        return $this->createdResponse(new InvoiceResource($invoice), 'Invoice berhasil dibuat');
    }

    public function show(int $id): JsonResponse
    {
        $invoice = $this->service->findHeaderOrFail($id);
        $this->authorizeInvoiceAccess($invoice);
        return $this->successResponse(new InvoiceResource($invoice));
    }

    public function items(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $invoice = Invoice::findOrFail($id);
        $this->authorizeInvoiceAccess($invoice);
        $query   = $invoice->items()->with('barang')->orderBy('id');

        if ($request->boolean('all')) {
            return $this->successResponse(InvoiceItemResource::collection($query->get()));
        }

        $perPage = (int) $request->input('per_page', 50);
        $items   = $query->paginate($perPage);

        return $this->paginatedResponse($items->through(fn($item) => new InvoiceItemResource($item)));
    }

    public function pembayaran(int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $this->authorizeInvoiceAccess($invoice);

        // Sumber A: pembayaran invoice-tunggal lama (header invoice_id = invoice ini).
        $singleRows = $invoice->pembayarans()->with('createdBy')->get()->map(fn($p) => [
            'id'                          => $p->id,
            'tanggal_pembayaran'          => $p->tanggal_pembayaran?->format('d-m-Y'),
            'jumlah_pembayaran'           => (float) $p->jumlah_pembayaran,
            'metode_pembayaran'           => $p->metode_pembayaran,
            'no_referensi'                => $p->no_referensi,
            'keterangan'                  => $p->keterangan,
            'created_by_name'             => $p->createdBy?->username,
            'created_at'                  => $p->created_at,
            'bukti_file_name'             => $p->bukti_file_name,
            'bukti_mime_type'             => $p->bukti_mime_type,
            'bukti_url'                   => $p->bukti_path
                ? URL::temporarySignedRoute(
                    'pembayaran.public-bukti', now()->addDays(30), ['pembayaran' => $p->id]
                )
                : null,
            'is_multi_payment'            => false,
            'multi_payment_invoice_count' => null,
        ]);

        // Sumber B: alokasi Multi Payment (header pembayaran_ar.invoice_id NULL,
        // jumlah untuk invoice ini hidup di tb_pembayaran_ar_items) yang menyentuh
        // invoice ini — lihat PembayaranArService::createMultiPayment(). Tanpa ini,
        // Riwayat Pembayaran selalu kosong untuk invoice yang dilunasi lewat Multi
        // Payment walau total_pembayaran/sisa_tagihan-nya sudah benar (dihitung
        // aditif di InvoiceService::recalculate()).
        $multiRows = PembayaranArItem::with('pembayaranAr.createdBy')
            ->where('invoice_id', $invoice->id)
            ->get()
            ->map(function (PembayaranArItem $item) {
                $header      = $item->pembayaranAr;
                $otherCount  = max(0, $header->items()->count() - 1);
                $keterangan  = trim(($header->keterangan ? $header->keterangan . ' ' : '')
                    . ($otherCount > 0
                        ? "(Multi Payment — turut melunasi {$otherCount} invoice lain)"
                        : '(Multi Payment)'));

                return [
                    'id'                          => $header->id,
                    'tanggal_pembayaran'          => $header->tanggal_pembayaran?->format('d-m-Y'),
                    'jumlah_pembayaran'           => (float) $item->jumlah_dialokasikan,
                    'metode_pembayaran'           => $header->metode_pembayaran,
                    'no_referensi'                => $header->no_referensi,
                    'keterangan'                  => $keterangan,
                    'created_by_name'             => $header->createdBy?->username,
                    'created_at'                  => $header->created_at,
                    'bukti_file_name'             => $header->bukti_file_name,
                    'bukti_mime_type'             => $header->bukti_mime_type,
                    'bukti_url'                   => $header->bukti_path
                        ? URL::temporarySignedRoute(
                            'pembayaran.public-bukti', now()->addDays(30), ['pembayaran' => $header->id]
                        )
                        : null,
                    'is_multi_payment'            => true,
                    'multi_payment_invoice_count' => $otherCount,
                ];
            });

        $rows = $singleRows->concat($multiRows)
            ->sortBy('created_at')
            ->values()
            ->map(fn($r) => [...$r, 'created_at' => $r['created_at']?->toIso8601String()]);

        return $this->successResponse($rows);
    }

    public function approvalLogs(int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $this->authorizeInvoiceAccess($invoice);
        $logs    = $invoice->approvalLogs()->with('actor')->get();

        return $this->successResponse($logs->map(fn($log) => [
            'id'         => $log->id,
            'action'     => $log->action,
            'note'       => $log->note,
            'actor_id'   => $log->actor_id,
            'actor_name' => $log->actor?->username,
            'created_at' => $log->created_at?->toIso8601String(),
        ])->values());
    }

    public function koreksi(int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $this->authorizeInvoiceAccess($invoice);
        $rows    = $invoice->endingBalanceKoreksi()->with(['submittedBy', 'spv', 'manager'])->get();

        return $this->successResponse($rows->map(fn($k) => [
            'id'                  => $k->id,
            'tipe'                => $k->tipe,
            'no_dokumen'          => $k->no_dokumen,
            'nilai_koreksi'       => (float) $k->nilai_koreksi,
            'alasan_koreksi'      => $k->alasan_koreksi,
            'status'              => $k->status,
            'submitted_by'        => $k->submittedBy?->username,
            'submitted_at'        => $k->submitted_at?->toIso8601String(),
            'spv'                 => $k->spv?->username,
            'manager'             => $k->manager?->username,
            'manager_actioned_at' => $k->manager_actioned_at?->toIso8601String(),
        ])->values());
    }

    public function update(UpdateInvoiceRequest $request, int $id): JsonResponse
    {
        $invoice = $this->service->findOrFail($id);
        $updated = $this->service->update($invoice, InvoiceDTO::fromRequest($request->validated()));
        return $this->successResponse(new InvoiceResource($updated), 'Invoice berhasil diperbarui');
    }

    public function changeStatus(Request $request, int $id): JsonResponse
    {
        $request->validate(['status' => ['required', 'in:TERKIRIM,SEBAGIAN,LUNAS']]);
        $invoice = $this->service->findOrFail($id);
        $updated = $this->service->changeStatus($invoice, $request->status);
        return $this->successResponse(new InvoiceResource($updated), 'Status invoice berhasil diubah');
    }

    public function recalculate(Invoice $invoice): JsonResponse
    {
        $this->service->recalculate($invoice->fresh());
        return $this->successResponse(
            new InvoiceResource($this->service->findOrFail($invoice->id)),
            'Recalculate invoice berhasil'
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $invoice = $this->service->findOrFail($id);
        $this->service->delete($invoice);
        return $this->successResponse(null, 'Invoice berhasil dihapus');
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = $this->service->bulkDelete($request->ids);

        return $this->successResponse(
            ['deleted' => $deleted],
            "{$deleted} invoice berhasil dihapus"
        );
    }

    /**
     * Filter export invoice reguler (bukan opening balance), sudah termasuk scoping
     * role via ArFilterScope — dipakai bersama oleh export(), exportExcel(), dan
     * exportRowCount() supaya definisi filter tidak terduplikasi.
     */
    private function resolveExportFilters(Request $request): array
    {
        $user    = auth()->user();
        $filters = $request->only([
            'search', 'status', 'klien_ar_id', 'karyawan_id',
            'tanggal_dari', 'tanggal_sampai', 'segment',
        ]);
        $filters['is_opening_balance'] = false;
        ArFilterScope::apply($filters, $user);

        return $filters;
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->resolveExportFilters($request);

        $invoices = $this->service->paginate(
            array_merge($filters, ['per_page' => 9999]),
            with: ['klienAr' => fn($q) => $q->withTrashed(), 'resto', 'perusahaan']
        )->items();

        $headers = [
            'No Invoice', 'Klien', 'Resto', 'Perusahaan', 'Tanggal Invoice',
            'Subtotal', 'Tagihan Sebelumnya',
            'Total Tagihan', 'Total Pembayaran', 'Sisa Tagihan', 'Status',
        ];

        return response()->streamDownload(function () use ($invoices, $headers) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
            fputcsv($handle, $headers);

            foreach ($invoices as $inv) {
                fputcsv($handle, [
                    $inv->no_invoice,
                    $inv->klienAr?->nama_klien,
                    $inv->resto?->nama_resto ?? '-',
                    $inv->perusahaan?->nama_singkatan_perusahaan,
                    $inv->tanggal_invoice?->format('d-m-Y'),
                    $inv->subtotal,
                    $inv->tagihan_periode_sebelumnya,
                    $inv->total_tagihan,
                    $inv->total_pembayaran,
                    $inv->sisa_tagihan,
                    $inv->status,
                ]);
            }
            fclose($handle);
        }, 'invoice-ar-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private const EXPORT_HEADERS = [
        'No Invoice', 'No Invoice Resto', 'Klien', 'Resto', 'Kode Resto', 'Stokis', 'Entitas',
        'Tanggal Invoice', 'Status', 'Kode Barang', 'Nama Barang', 'Satuan', 'QTY', 'Harga Satuan',
        'Total Item',
    ];

    /**
     * Jumlah baris (level-item) yang akan dihasilkan export untuk filter yang sama —
     * dipakai FE untuk peringatan real-time XLSX vs CSV di modal Export Invoice.
     * Sengaja ringan: cuma WHERE ... IN + COUNT, tanpa hydrate model.
     */
    public function exportRowCount(Request $request): JsonResponse
    {
        $filters    = $this->resolveExportFilters($request);
        $invoiceIds = $this->service->getExportIds($filters);
        $rowCount   = InvoiceItem::whereIn('invoice_id', $invoiceIds)->count();

        return $this->successResponse(['row_count' => $rowCount]);
    }

    /**
     * Field level-invoice dihitung sekali per invoice (dipakai berulang di tiap baris item),
     * termasuk kode/nama/stokis resto invoice & klien supaya loop item TIDAK perlu lagi
     * eager-load relasi 'invoice' (lebih ringan buat dibaca per-batch).
     * Nama resto SENGAJA TIDAK final di sini — invoice "Konsolidasi" B2B bisa mencakup banyak
     * resto berbeda dalam 1 tagihan, jadi resolusi akhir tetap PER ITEM (lihat resolveExportRow()).
     */
    private function buildExportInvoiceMeta(Collection $invoices): array
    {
        $invoiceMeta = [];
        foreach ($invoices as $inv) {
            $entitasP = $inv->klienAr?->resto?->perusahaan ?? $inv->perusahaan;

            $invoiceMeta[$inv->id] = [
                'no_invoice'         => $inv->no_invoice,
                'klien'              => $inv->klienAr?->nama_klien ?? '-',
                'entitas'            => $entitasP?->nama_perusahaan ?? '-',
                'tanggal_invoice'    => $inv->tanggal_invoice?->format('d-m-Y') ?? '-',
                'status'             => $inv->status,
                'resto_kode'         => $inv->resto?->kode_resto,
                'resto_nama'         => $inv->resto?->nama_resto,
                'resto_stokis'       => $inv->resto?->stokis,
                'klien_resto_kode'   => $inv->klienAr?->resto?->kode_resto,
                'klien_resto_nama'   => $inv->klienAr?->resto?->nama_resto,
                'klien_resto_stokis' => $inv->klienAr?->resto?->stokis,
            ];
        }

        return $invoiceMeta;
    }

    private function buildExportRestoLookup(Collection $invoiceIds, array $invoiceMeta): Collection
    {
        $kodeRestoSet = InvoiceItem::whereIn('invoice_id', $invoiceIds)
            ->whereNotNull('kode_resto')
            ->distinct()
            ->pluck('kode_resto');

        foreach ($invoiceMeta as $meta) {
            if ($meta['resto_kode'])       $kodeRestoSet->push($meta['resto_kode']);
            if ($meta['klien_resto_kode']) $kodeRestoSet->push($meta['klien_resto_kode']);
        }

        return Resto::whereIn('kode_resto', $kodeRestoSet->unique()->values())->get()->keyBy('kode_resto');
    }

    /**
     * Query item export, ramping (kolom terpilih + relasi 'barang' minimal saja) supaya
     * aman dibaca per-batch (chunk by invoice_id) untuk data puluhan-ratusan ribu baris.
     */
    private function exportItemQuery(Collection $invoiceIds): \Illuminate\Database\Eloquent\Builder
    {
        return InvoiceItem::query()
            ->select(['id', 'invoice_id', 'no_invoice_resto', 'kode_resto', 'nama_resto', 'kode_barang', 'barang_id', 'nama_barang', 'satuan', 'qty', 'harga_satuan', 'subtotal'])
            ->with('barang:id,kode_barang')
            ->whereIn('invoice_id', $invoiceIds)
            ->orderBy('invoice_id')
            ->orderBy('id');
    }

    private function resolveExportRow(InvoiceItem $d, array $meta, Collection $restosByKode): array
    {
        $resolvedKode = $d->kode_resto
            ?? ($meta['resto_kode'] ?? null)
            ?? ($meta['klien_resto_kode'] ?? null);

        // Per item, BUKAN per invoice — 1 invoice "Konsolidasi" B2B bisa mencakup
        // banyak resto berbeda dalam 1 tagihan (tiap resto kirim barang sendiri).
        $restoNama = $d->nama_resto
            ?: ($resolvedKode ? $restosByKode->get($resolvedKode)?->nama_resto : null)
            ?: ($meta['resto_nama'] ?? null)
            ?: ($meta['klien_resto_nama'] ?? null)
            ?: '-';

        $stokis = ($meta['resto_stokis'] ?? null)
            ?? ($meta['klien_resto_stokis'] ?? null)
            ?? ($resolvedKode ? $restosByKode->get($resolvedKode)?->stokis : null)
            ?? '-';

        return [
            $meta['no_invoice']      ?? '-',
            $d->no_invoice_resto     ?? '-',
            $meta['klien']           ?? '-',
            $restoNama,
            $resolvedKode            ?? '-',
            $stokis,
            $meta['entitas']         ?? '-',
            $meta['tanggal_invoice'] ?? '-',
            $meta['status']          ?? '-',
            $d->kode_barang ?? $d->barang?->kode_barang ?? '-',
            $d->nama_barang,
            $d->satuan ?? '-',
            (float) $d->qty,
            (float) $d->harga_satuan,
            (float) $d->subtotal,
        ];
    }

    public function exportExcel(Request $request): StreamedResponse|BinaryFileResponse
    {
        $format = strtolower((string) $request->query('format', 'csv'));

        return $format === 'xlsx' ? $this->streamXlsxExport($request) : $this->streamCsvExport($request);
    }

    /**
     * Export CSV gabungan (1 baris = 1 item barang, kolom level-invoice diulang per baris).
     * Item dibaca per-batch invoice_id (bukan satu ->get() raksasa) supaya memori tetap flat
     * untuk data puluhan-ratusan ribu baris — lihat buildExportInvoiceMeta()/exportItemQuery().
     */
    private function streamCsvExport(Request $request): StreamedResponse
    {
        $filters      = $this->resolveExportFilters($request);
        $invoices     = $this->service->getAllForExport($filters);
        $invoiceIds   = $invoices->pluck('id');
        $invoiceMeta  = $this->buildExportInvoiceMeta($invoices);
        $restosByKode = $this->buildExportRestoLookup($invoiceIds, $invoiceMeta);
        $headers      = self::EXPORT_HEADERS;

        return response()->streamDownload(function () use ($invoiceIds, $invoiceMeta, $restosByKode, $headers) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
            // Titik koma, bukan koma — locale Excel Indonesia pakai ";" sebagai list
            // separator sistem, jadi file terbuka langsung terbagi rapi per kolom.
            fputcsv($handle, $headers, ';');

            $invoiceIds->chunk(500)->each(function ($batchIds) use ($handle, $invoiceMeta, $restosByKode) {
                $this->exportItemQuery($batchIds)->get()->each(function ($d) use ($handle, $invoiceMeta, $restosByKode) {
                    $meta = $invoiceMeta[$d->invoice_id] ?? [];
                    fputcsv($handle, $this->resolveExportRow($d, $meta, $restosByKode), ';');
                });
            });

            fclose($handle);
        }, 'invoice-ar-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Export XLSX asli (PhpSpreadsheet). Styling SENGAJA cuma di header row — jangan tiru
     * pola styling per-sel di dalam loop seperti exportB2BDelivery(), itu justru penyebab
     * lama versi Excel 2-sheet dulu diganti CSV (lihat riwayat exportExcel()). Direkomendasikan
     * hanya untuk data <= ~13.000 baris (lihat exportRowCount() + peringatan di FE), tapi
     * tidak diblokir keras di sini kalau user tetap memilih XLSX untuk data besar.
     */
    private function streamXlsxExport(Request $request): BinaryFileResponse
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $filters      = $this->resolveExportFilters($request);
        $invoices     = $this->service->getAllForExport($filters);
        $invoiceIds   = $invoices->pluck('id');
        $invoiceMeta  = $this->buildExportInvoiceMeta($invoices);
        $restosByKode = $this->buildExportRestoLookup($invoiceIds, $invoiceMeta);

        $cols    = range('A', 'O'); // 15 kolom, sinkron dengan self::EXPORT_HEADERS
        $lastCol = 'O';

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Invoice AR');

        foreach (array_combine($cols, self::EXPORT_HEADERS) as $col => $label) {
            $sheet->setCellValueExplicit("{$col}1", $label, DataType::TYPE_STRING);
        }
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
        ]);
        $sheet->freezePane('A2');

        $numericCols = ['M', 'N', 'O']; // QTY, Harga Satuan, Total Item
        $rowNum      = 2;

        $invoiceIds->chunk(500)->each(function ($batchIds) use ($sheet, &$rowNum, $invoiceMeta, $restosByKode, $cols, $numericCols) {
            $this->exportItemQuery($batchIds)->get()->each(function ($d) use ($sheet, &$rowNum, $invoiceMeta, $restosByKode, $cols, $numericCols) {
                $meta = $invoiceMeta[$d->invoice_id] ?? [];
                $row  = $this->resolveExportRow($d, $meta, $restosByKode);

                foreach (array_combine($cols, $row) as $col => $val) {
                    $type = in_array($col, $numericCols, true) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING;
                    $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val, $type);
                }
                $rowNum++;
            });
        });

        if ($rowNum > 2) {
            $sheet->getStyle('M2:O' . ($rowNum - 1))->getNumberFormat()->setFormatCode('#,##0');
        }

        $temp     = tempnam(sys_get_temp_dir(), 'export_invoice_') . '.xlsx';
        $filename = 'Data Tagihan Invoice-' . now()->format('Ymd-His') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    public function publicPrint(string $token): Response
    {
        $invoice = \App\Models\Invoice::where('prepared_token', $token)->firstOrFail();
        abort_if(
            $invoice->requiresApproval() && !$invoice->isApprovedForFinanceFlow(),
            422,
            'Invoice belum disetujui, dokumen belum dapat diakses'
        );

        $invoice->load([
            'klienAr.karyawanAr.perusahaan',
            'klienAr.perusahaan',
            'perusahaan',
            'karyawan.perusahaan',
            'items.barang',
            'openingBalanceDetails.items.barang',
            'pembayarans',
            'createdBy.karyawan',
            'submittedBy.karyawan',
            'approvedBy.karyawan',
            'endingBalanceKoreksi' => fn($q) => $q
                ->whereIn('tipe', ['CREDIT_NOTE', 'DEBIT_NOTE'])
                ->where('status', 'APPROVED'),
        ]);

        $regularInvoicesInPeriod = collect();
        if ($invoice->is_opening_balance && $invoice->klien_ar_id && $invoice->tanggal_invoice) {
            $bulanAwal = \Carbon\Carbon::parse($invoice->tanggal_invoice)->startOfMonth();
            $bulanAkhir = \Carbon\Carbon::parse($invoice->tanggal_invoice)->endOfMonth();
            $regularInvoicesInPeriod = Invoice::query()
                ->where('klien_ar_id', $invoice->klien_ar_id)
                ->where('perusahaan_id', $invoice->perusahaan_id)
                ->where('is_opening_balance', false)
                ->whereBetween('tanggal_invoice', [
                    $bulanAwal->toDateString(),
                    $bulanAkhir->toDateString(),
                ])
                ->orderBy('tanggal_invoice', 'asc')
                ->get();
        }

        if ($regularInvoicesInPeriod->isNotEmpty()) {
            $regularInvoicesInPeriod->load([
                'klienAr.karyawanAr.perusahaan',
                'klienAr.perusahaan',
                'klienAr.resto.investor',
                'perusahaan',
                'karyawan.perusahaan',
                'resto',
                'items.barang',
                'pembayarans',
                'createdBy.karyawan',
                'submittedBy.karyawan',
                'approvedBy.karyawan',
            ]);
        }

        $this->attachPrintItems($invoice);

        $signatureData = $this->buildSignatureData($invoice);
        $filename = 'Invoice-' . str_replace(['/', '\\', ' '], '-', $invoice->no_invoice) . '.pdf';

        return Pdf::loadView('finance.invoice-print', compact('invoice', 'signatureData', 'regularInvoicesInPeriod'))
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => false,
                'defaultFont'          => 'Arial',
                'dpi'                  => 96,
            ])
            ->stream($filename);
    }

    public function print(Request $request, int $id): Response|string
    {
        $invoice = $this->service->findForPrintOrFail($id);

        $this->authorizeInvoiceAccess($invoice);

        abort_if(
            $invoice->requiresApproval() && !$invoice->isApprovedForFinanceFlow(),
            422,
            'Opening balance belum disetujui, dokumen belum dapat dicetak'
        );

        $regularInvoicesInPeriod = collect();
        if ($invoice->is_opening_balance && $invoice->klien_ar_id && $invoice->tanggal_invoice) {
            $bulanAwal = \Carbon\Carbon::parse($invoice->tanggal_invoice)->startOfMonth();
            $bulanAkhir = \Carbon\Carbon::parse($invoice->tanggal_invoice)->endOfMonth();
            $regularInvoicesInPeriod = Invoice::query()
                ->where('klien_ar_id', $invoice->klien_ar_id)
                ->where('perusahaan_id', $invoice->perusahaan_id)
                ->where('is_opening_balance', false)
                ->whereBetween('tanggal_invoice', [
                    $bulanAwal->toDateString(),
                    $bulanAkhir->toDateString(),
                ])
                ->orderBy('tanggal_invoice', 'asc')
                ->get();
        }

        if ($regularInvoicesInPeriod->isNotEmpty()) {
            $regularInvoicesInPeriod->load([
                'klienAr.karyawanAr.perusahaan',
                'klienAr.perusahaan',
                'klienAr.resto.investor',
                'perusahaan',
                'karyawan.perusahaan',
                'resto',
                'items.barang',
                'pembayarans',
                'createdBy.karyawan',
                'submittedBy.karyawan',
                'approvedBy.karyawan',
            ]);
        }

        $this->attachPrintItems($invoice);
        $regularInvoicesInPeriod->each(fn ($inv) => $this->attachPrintItems($inv));

        $signatureData = $this->buildSignatureData($invoice);
        $regularInvoicesSignatureData = $regularInvoicesInPeriod
            ->mapWithKeys(fn ($inv) => [$inv->id => $this->buildSignatureData($inv)])
            ->all();

        if ($request->has('html')) {
            return view('finance.invoice-print', compact('invoice', 'signatureData', 'regularInvoicesInPeriod', 'regularInvoicesSignatureData'))->render();
        }

        $filename = 'Invoice-' . str_replace(['/', '\\', ' '], '-', $invoice->no_invoice) . '.pdf';

        return Pdf::loadView('finance.invoice-print', compact('invoice', 'signatureData', 'regularInvoicesInPeriod', 'regularInvoicesSignatureData'))
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => false,
                'defaultFont'          => 'Arial',
                'dpi'                  => 96,
            ])
            ->stream($filename);
    }

    /**
     * Preview kandidat Bulk Print per Investor: dari 1 invoice B2C anchor,
     * kumpulkan invoice reguler B2C outlet lain milik investor yang sama
     * dalam periode yang diminta. Tidak menyertakan share_url (murni review).
     */
    public function bulkB2CInvestorPreview(Request $request): JsonResponse
    {
        $context = $this->resolveBulkB2CInvestorContext($request);

        return $this->successResponse($this->buildBulkB2CInvestorPayload($context, includeShareUrl: false));
    }

    /**
     * Sama seperti preview, tapi sekaligus generate 1 signed URL PDF gabungan
     * (dipakai FE untuk membangun pesan WhatsApp berisi 1 link).
     */
    public function bulkB2CInvestorLink(Request $request): JsonResponse
    {
        $context = $this->resolveBulkB2CInvestorContext($request);

        return $this->successResponse($this->buildBulkB2CInvestorPayload($context, includeShareUrl: true));
    }

    /**
     * Endpoint publik (signed URL, tanpa auth) untuk PDF gabungan bulk print
     * per investor. Himpunan invoice_id dibaca dari token (snapshot saat
     * link dibuat), tapi nilai tiap invoice selalu diambil ulang dari DB
     * supaya statusnya terkini.
     */
    public function publicBulkB2CPrint(string $token): Response
    {
        try {
            $payload = BulkPrintTokenCodec::decode($token);
        } catch (\Throwable) {
            abort(404, 'Dokumen tidak ditemukan atau tautan tidak valid');
        }

        $invoiceIds = $payload['invoice_ids'] ?? [];
        abort_if(empty($invoiceIds), 404, 'Dokumen tidak ditemukan');

        $invoices = $this->service->getBulkB2CInvestorInvoices(
            investorIds: null,
            onlyInvoiceIds: $invoiceIds,
        );

        abort_if($invoices->isEmpty(), 404, 'Dokumen tidak ditemukan');

        $invoices->each(fn($inv) => $this->attachPrintItems($inv));
        $signaturesById = $invoices
            ->mapWithKeys(fn($inv) => [$inv->id => $this->buildSignatureData($inv)])
            ->all();

        $restoGroups = $this->groupInvoicesByResto($invoices);
        $filenameBase = $payload['investor_nama'] ?? 'Investor';
        $filename = 'Rekap-Invoice-' . str_replace(['/', '\\', ' '], '-', $filenameBase) . '.pdf';

        return Pdf::loadView('finance.invoice-b2c-bulk-print', [
            'payload'        => $payload,
            'invoices'       => $invoices,
            'signaturesById' => $signaturesById,
            'restoGroups'    => $restoGroups,
        ])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => false,
                'defaultFont'          => 'Arial',
                'dpi'                  => 96,
            ])
            ->stream($filename);
    }

    private function resolveBulkB2CInvestorContext(Request $request): array
    {
        $data = $request->validate([
            'anchor_invoice_id' => ['required', 'integer'],
            'tanggal_dari'      => ['required', 'date'],
            'tanggal_sampai'    => ['required', 'date', 'after_or_equal:tanggal_dari'],
        ]);

        $anchor = $this->service->findForPrintOrFail((int) $data['anchor_invoice_id']);

        abort_if(
            $anchor->is_opening_balance,
            422,
            'Invoice ini adalah Opening Balance, tidak didukung untuk bulk print per investor.'
        );
        abort_if(
            strtoupper((string) $anchor->klienAr?->tipe_klien) !== 'RESTO',
            422,
            'Invoice ini bukan invoice B2C (RESTO).'
        );

        if ($anchor->klien_ar_id) {
            $this->authorizeKlienArOwnership($anchor->klien_ar_id);
        }

        $investor = $anchor->klienAr?->resto?->investor;
        abort_if(!$investor, 422, 'Outlet pada invoice ini belum terhubung ke data Investor.');

        $investorIds = $this->service->resolveMatchingInvestorIds($investor);

        $invoices = $this->service->getBulkB2CInvestorInvoices(
            investorIds: $investorIds,
            tanggalDari: $data['tanggal_dari'],
            tanggalSampai: $data['tanggal_sampai'],
            picArKaryawanId: RoleHelper::picArKaryawanIdFor(auth()->user()),
        );

        abort_if(
            $invoices->count() > 200,
            422,
            'Jumlah invoice pada periode ini melebihi 200. Silakan persempit rentang tanggal.'
        );

        return [
            'anchor'         => $anchor,
            'investor'       => $investor,
            'invoices'       => $invoices,
            'tanggal_dari'   => $data['tanggal_dari'],
            'tanggal_sampai' => $data['tanggal_sampai'],
        ];
    }

    private function buildBulkB2CInvestorPayload(array $ctx, bool $includeShareUrl): array
    {
        $anchor    = $ctx['anchor'];
        $investor  = $ctx['investor'];
        $invoices  = $ctx['invoices'];

        $restoGroups = $this->groupInvoicesByResto($invoices);

        $totalTagihan    = (float) $invoices->sum(fn($inv) => (float) $inv->subtotal);
        $totalPembayaran = (float) $invoices->sum(fn($inv) => (float) $inv->total_pembayaran);
        $totalSisa       = (float) $invoices->sum(fn($inv) => max(
            0,
            (float) $inv->subtotal - (float) $inv->total_pembayaran - (float) $inv->total_penyesuaian
        ));

        $result = [
            'investor' => [
                'id'            => $investor->id,
                'nama_investor' => $investor->nama_investor,
            ],
            'klien_anchor' => [
                'id'         => $anchor->klienAr?->id,
                'nama_klien' => $anchor->klienAr?->nama_klien,
                'no_wa'      => $anchor->klienAr?->no_wa,
            ],
            'pic_ar' => [
                'nama_karyawan' => $anchor->klienAr?->karyawanAr?->nama_karyawan,
            ],
            'periode' => [
                'tanggal_dari'   => $ctx['tanggal_dari'],
                'tanggal_sampai' => $ctx['tanggal_sampai'],
            ],
            'total_invoice'    => $invoices->count(),
            'total_resto'      => count($restoGroups),
            'total_tagihan'    => $totalTagihan,
            'total_pembayaran' => $totalPembayaran,
            'total_sisa'       => $totalSisa,
            'resto_groups'     => array_map(fn($group) => [
                'resto_id'            => $group['resto_id'],
                'nama_resto'          => $group['nama_resto'],
                'kode_resto'          => $group['kode_resto'],
                'subtotal_tagihan'    => $group['subtotal_tagihan'],
                'subtotal_pembayaran' => $group['subtotal_pembayaran'],
                'subtotal_sisa'       => $group['subtotal_sisa'],
                'invoices'            => array_map(fn($inv) => [
                    'id'               => $inv->id,
                    'no_invoice'       => $inv->no_invoice,
                    'tanggal_invoice'  => $inv->tanggal_invoice?->format('d-m-Y'),
                    'subtotal'         => (float) $inv->subtotal,
                    'total_pembayaran' => (float) $inv->total_pembayaran,
                    'sisa'             => max(0, (float) $inv->subtotal - (float) $inv->total_pembayaran - (float) $inv->total_penyesuaian),
                    'status'           => $inv->status,
                ], $group['invoices']),
            ], $restoGroups),
        ];

        if ($includeShareUrl) {
            $token = BulkPrintTokenCodec::encode([
                'invoice_ids'       => $invoices->pluck('id')->all(),
                'investor_id'       => $investor->id,
                'investor_nama'     => $investor->nama_investor,
                'klien_anchor_nama' => $anchor->klienAr?->nama_klien,
                'pic_ar_nama'       => $anchor->klienAr?->karyawanAr?->nama_karyawan,
                'tanggal_dari'      => $ctx['tanggal_dari'],
                'tanggal_sampai'    => $ctx['tanggal_sampai'],
            ]);

            $result['share_url'] = URL::temporarySignedRoute(
                'invoice.bulk-b2c-print', now()->addDays(30), ['token' => $token]
            );
        }

        return $result;
    }

    /**
     * @return array<int, array{resto_id:?int,nama_resto:string,kode_resto:?string,invoices:array,subtotal_tagihan:float,subtotal_pembayaran:float,subtotal_sisa:float}>
     */
    private function groupInvoicesByResto(Collection $invoices): array
    {
        $groups = [];

        foreach ($invoices as $inv) {
            $resto = $inv->klienAr?->resto ?? $inv->resto;
            $key   = $resto?->id ?? 0;

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'resto_id'            => $resto?->id,
                    'nama_resto'          => $resto?->nama_resto ?? '-',
                    'kode_resto'          => $resto?->kode_resto,
                    'invoices'            => [],
                    'subtotal_tagihan'    => 0.0,
                    'subtotal_pembayaran' => 0.0,
                    'subtotal_sisa'       => 0.0,
                ];
            }

            $sisa = max(0, (float) $inv->subtotal - (float) $inv->total_pembayaran - (float) $inv->total_penyesuaian);

            $groups[$key]['invoices'][]          = $inv;
            $groups[$key]['subtotal_tagihan']    += (float) $inv->subtotal;
            $groups[$key]['subtotal_pembayaran'] += (float) $inv->total_pembayaran;
            $groups[$key]['subtotal_sisa']       += $sisa;
        }

        return array_values($groups);
    }

    public function exportB2BDelivery(Request $request): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $filters = $request->only(['tanggal_dari', 'tanggal_sampai', 'klien_ar_id']);

        $details = InvoiceItem::with(['invoice.klienAr.resto', 'invoice.resto', 'invoice.perusahaan', 'barang'])
            ->whereNotNull('no_invoice_resto')
            ->whereHas('invoice', function ($q) use ($filters) {
                $q->where('is_opening_balance', false)
                  ->whereNotNull('tanggal_kirim_barang')
                  ->when($filters['tanggal_dari'] ?? null, fn($q, $v) =>
                      $q->where('tanggal_kirim_barang', '>=', $v)
                  )
                  ->when($filters['tanggal_sampai'] ?? null, fn($q, $v) =>
                      $q->where('tanggal_kirim_barang', '<=', $v)
                  )
                  ->when($filters['klien_ar_id'] ?? null, fn($q, $v) =>
                      $q->where('klien_ar_id', $v)
                  );
            })
            ->orderBy('invoice_id')
            ->orderBy('id')
            ->get();

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Pengiriman B2B');

        $cols = [
            'A' => ['NOMOR INVOICE',   28],
            'B' => ['Kode Barang',     16],
            'C' => ['Nama Barang',     36],
            'D' => ['Satuan',          12],
            'E' => ['QTY',             10],
            'F' => ['Harga Satuan',    18],
            'G' => ['TOTAL',           18],
            'H' => ['Stokis',           28],
            'I' => ['Tanggal Kirim',   16],
            'J' => ['Kode Resto',      16],
            'K' => ['Nama Klien',      32],
            'L' => ['Entitas',         24],
            'M' => ['NOMOR INVOICE 2', 28],
        ];
        $lastCol = 'M';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'DATA PENGIRIMAN B2B KONSOLIDASI');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0D47A1']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', 'Diekspor pada: ' . now()->format('d-m-Y H:i') . '   |   Total baris: ' . $details->count());
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF1565C0']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE3F2FD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(22);
        $sheet->getRowDimension(3)->setRowHeight(6);

        foreach ($cols as $col => [$label, $width]) {
            $sheet->setCellValue("{$col}4", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF0D47A1']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(22);

        $b2bKodeRestoSet = collect();
        foreach ($details as $_d) {
            if ($_d->kode_resto)                                $b2bKodeRestoSet->push($_d->kode_resto);
            if ($_d->invoice?->resto?->kode_resto)              $b2bKodeRestoSet->push($_d->invoice->resto->kode_resto);
            if ($_d->invoice?->klienAr?->resto?->kode_resto)    $b2bKodeRestoSet->push($_d->invoice->klienAr->resto->kode_resto);
        }
        $b2bRestosByKode = Resto::whereIn('kode_resto', $b2bKodeRestoSet->unique()->values())->get()->keyBy('kode_resto');

        $rowNum = 5;
        foreach ($details as $d) {
            $inv = $d->invoice;
            $bg  = $rowNum % 2 === 0 ? 'FFE3F2FD' : 'FFFFFFFF';

            $b2bResolvedKode = $d->kode_resto
                ?? $inv?->resto?->kode_resto
                ?? $inv?->klienAr?->resto?->kode_resto;

            $b2bStokis = $inv?->resto?->stokis
                ?? $inv?->klienAr?->resto?->stokis
                ?? ($b2bResolvedKode ? $b2bRestosByKode->get($b2bResolvedKode)?->stokis : null)
                ?? '-';

            $rowData = [
                'A' => [$d->no_invoice_resto          ?? '-',                         DataType::TYPE_STRING],
                'B' => [$d->kode_barang ?? $d->barang?->kode_barang ?? '-',            DataType::TYPE_STRING],
                'C' => [$d->nama_barang,                                             DataType::TYPE_STRING],
                'D' => [$d->satuan                    ?? '-',                         DataType::TYPE_STRING],
                'E' => [(float) $d->qty,                                            DataType::TYPE_NUMERIC],
                'F' => [(float) $d->harga_satuan,                                   DataType::TYPE_NUMERIC],
                'G' => [(float) $d->subtotal,                                       DataType::TYPE_NUMERIC],
                'H' => [$b2bStokis,                                                 DataType::TYPE_STRING],
                'I' => [$inv?->tanggal_kirim_barang?->format('d-m-Y') ?? '-',       DataType::TYPE_STRING],
                'J' => [$d->kode_resto       ?? '-',                                DataType::TYPE_STRING],
                'K' => [$inv?->klienAr?->nama_klien ?? '-',                         DataType::TYPE_STRING],
                'L' => [$inv?->perusahaan?->nama_singkatan_perusahaan ?? '-',       DataType::TYPE_STRING],
                'M' => [$inv?->no_invoice ?? '-',                                   DataType::TYPE_STRING],
            ];

            foreach ($rowData as $col => [$val, $type]) {
                $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val, $type);
            }
            foreach (['E', 'F', 'G'] as $numCol) {
                $sheet->getStyle("{$numCol}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
            }
            $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($rowNum)->setRowHeight(18);
            $rowNum++;
        }

        if ($rowNum > 5) {
            $sheet->getStyle("A4:{$lastCol}" . ($rowNum - 1))->applyFromArray([
                'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF1565C0']]],
            ]);
        }

        $sheet->freezePane('A5');

        $temp     = tempnam(sys_get_temp_dir(), 'export_b2b_delivery_') . '.xlsx';
        $filename = 'Data Pengiriman B2B-' . now()->format('Ymd-His') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    private function buildSignatureData($invoice): array
    {
        if ($invoice->is_opening_balance) {
            $preparedByUser = $invoice->submittedBy ?: $invoice->createdBy;
            $preparedByName = $preparedByUser?->karyawan?->nama_karyawan
                ?? $preparedByUser?->username
                ?? '___________________';

            $preparedPayload = SignatureBarcodeHelper::buildObPreparedPayload($invoice, $preparedByName);
        } else {
            $preparedByName = $invoice->klienAr?->karyawanAr?->nama_karyawan ?? '___________________';

            $preparedPayload = SignatureBarcodeHelper::buildInvoicePreparedPayload($invoice, $preparedByName);
        }

        return [
            'prepared_by_name' => $preparedByName,
            'prepared_qr_src'  => SignatureBarcodeHelper::generateDataUri($preparedPayload, 250),
            'approved_by_name' => null,
            'approved_qr_src'  => null,
        ];
    }

    /**
     * Item khusus untuk cetak: invoice PT diringkas (lihat InvoicePrintItemAggregator),
     * invoice RESTO/B2C tetap memakai item asli.
     */
    private function attachPrintItems(Invoice $invoice): void
    {
        $invoice->printItems = $invoice->klienAr?->tipe_klien === 'PT'
            ? InvoicePrintItemAggregator::aggregate($invoice->items)
            : $invoice->items;
    }
}
