<?php

namespace App\Domain\Finance\Invoice\Controllers;

use App\Domain\Finance\Invoice\DTO\InvoiceDTO;
use App\Domain\Finance\Invoice\Jobs\UploadInvoiceToGDriveJob;
use App\Domain\Finance\Invoice\Requests\StoreInvoiceRequest;
use App\Domain\Finance\Invoice\Requests\UpdateInvoiceRequest;
use App\Domain\Finance\Invoice\Resources\InvoiceItemResource;
use App\Domain\Finance\Invoice\Resources\InvoiceListResource;
use App\Domain\Finance\Invoice\Resources\InvoiceResource;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\KlienAr;
use App\Models\Resto;
use Carbon\Carbon;
use App\Support\Helpers\ArFilterScope;
use App\Support\Helpers\RoleHelper;
use App\Support\Helpers\SignatureBarcodeHelper;
use App\Support\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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
            ->get()
            ->map(fn($inv) => [
                'id'              => $inv->id,
                'no_invoice'      => $inv->no_invoice,
                'tanggal_invoice' => $inv->tanggal_invoice?->toDateString(),
                'subtotal'        => (float) $inv->subtotal,
                'total_tagihan'   => (float) $inv->total_tagihan,
                'sisa_tagihan'    => max(0.0, (float) $inv->subtotal - (float) $inv->total_pembayaran - (float) $inv->total_penyesuaian),
                'status'          => $inv->status,
                'keterangan'      => $inv->keterangan,
                'items'           => $inv->items->map(fn($item) => [
                    'barang_id'    => $item->barang_id,
                    'kode_barang'  => $item->kode_barang ?? $item->barang?->kode_barang ?? '',
                    'nama_barang'  => $item->nama_barang,
                    'qty'          => (float) $item->qty,
                    'satuan'       => $item->satuan ?? 'pcs',
                    'harga_satuan' => (float) $item->harga_satuan,
                    'subtotal'     => (float) $item->subtotal,
                    'keterangan'   => $item->keterangan ?? '',
                ])->values()->all(),
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
        UploadInvoiceToGDriveJob::dispatch($invoice->id);

        if ($invoice->klien_ar_id && $invoice->tanggal_invoice) {
            $monthStart = \Carbon\Carbon::parse($invoice->tanggal_invoice)->startOfMonth()->toDateString();
            $monthEnd   = \Carbon\Carbon::parse($invoice->tanggal_invoice)->endOfMonth()->toDateString();
            Invoice::where('klien_ar_id', $invoice->klien_ar_id)
                ->where('is_opening_balance', true)
                ->where('approval_status', 'APPROVED')
                ->whereBetween('tanggal_invoice', [$monthStart, $monthEnd])
                ->each(fn($ob) => UploadInvoiceToGDriveJob::dispatch($ob->id));
        }

        return $this->createdResponse(new InvoiceResource($invoice), 'Invoice berhasil dibuat');
    }

    public function show(int $id): JsonResponse
    {
        $invoice = $this->service->findHeaderOrFail($id);
        return $this->successResponse(new InvoiceResource($invoice));
    }

    public function items(int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $items   = $invoice->items()->with('barang')->get();

        return $this->successResponse(InvoiceItemResource::collection($items));
    }

    public function pembayaran(int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $rows    = $invoice->pembayarans()->with('createdBy')->get();

        return $this->successResponse($rows->map(fn($p) => [
            'id'                 => $p->id,
            'tanggal_pembayaran' => $p->tanggal_pembayaran?->format('d-m-Y'),
            'jumlah_pembayaran'  => (float) $p->jumlah_pembayaran,
            'metode_pembayaran'  => $p->metode_pembayaran,
            'no_referensi'       => $p->no_referensi,
            'keterangan'         => $p->keterangan,
            'created_by_name'    => $p->createdBy?->username,
            'created_at'         => $p->created_at?->toIso8601String(),
        ]));
    }

    public function approvalLogs(int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
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

    public function export(Request $request): StreamedResponse
    {
        $user    = auth()->user();
        $filters = $request->only([
            'search', 'status', 'klien_ar_id', 'karyawan_id',
            'tanggal_dari', 'tanggal_sampai', 'segment',
        ]);
        $filters['is_opening_balance'] = false;
        ArFilterScope::apply($filters, $user);

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

    public function exportExcel(Request $request): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $user    = auth()->user();
        $filters = $request->only([
            'search', 'status', 'klien_ar_id', 'karyawan_id',
            'tanggal_dari', 'tanggal_sampai', 'segment',
        ]);
        $filters['is_opening_balance'] = false;
        ArFilterScope::apply($filters, $user);

        $invoices = $this->service->getAllForExport($filters);
        $invoices->load('items:id,invoice_id,nama_resto');
        $invoiceIds = $invoices->pluck('id');

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Tagihan Invoice');

        $cols = [
            'A' => ['No Invoice',             24],
            'B' => ['Klien',                  32],
            'C' => ['Resto',                  28],
            'D' => ['Entitas',                28],
            'E' => ['Tanggal Invoice',        18],
            'F' => ['Subtotal',               18],
            'G' => ['Tagihan Sebelumnya',     22],
            'H' => ['Total Tagihan',          18],
            'I' => ['Total Pembayaran',       20],
            'J' => ['Sisa Tagihan',           18],
            'K' => ['Status',                 14],
        ];
        $lastCol = 'K';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'DATA TAGIHAN INVOICE');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0D47A1']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', 'Diekspor pada: ' . now()->format('d-m-Y H:i') . '   |   Total data: ' . $invoices->count() . ' invoice');
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

        $statusColors = [
            'DRAFT'    => 'FF757575',
            'TERKIRIM' => 'FF1565C0',
            'SEBAGIAN' => 'FFE65100',
            'LUNAS'    => 'FF2E7D32',
        ];

        $rowNum = 5;
        foreach ($invoices as $inv) {
            $bg = $rowNum % 2 === 0 ? 'FFE3F2FD' : 'FFFFFFFF';

            $restoNama = $inv->resto?->nama_resto
                ?? $inv->klienAr?->resto?->nama_resto
                ?? ($inv->items->pluck('nama_resto')->filter()->unique()->first() ?: '-');

            $entitasP = $inv->klienAr?->resto?->perusahaan ?? $inv->perusahaan;
            $entitas  = $entitasP?->nama_perusahaan ?? '-';

            $rowData = [
                'A' => [$inv->no_invoice,                                   DataType::TYPE_STRING],
                'B' => [$inv->klienAr?->nama_klien ?? '-',                  DataType::TYPE_STRING],
                'C' => [$restoNama,                                         DataType::TYPE_STRING],
                'D' => [$entitas,                                           DataType::TYPE_STRING],
                'E' => [$inv->tanggal_invoice?->format('d-m-Y') ?? '-',    DataType::TYPE_STRING],
                'F' => [(float) $inv->subtotal,                             DataType::TYPE_NUMERIC],
                'G' => [(float) $inv->tagihan_periode_sebelumnya,           DataType::TYPE_NUMERIC],
                'H' => [(float) $inv->total_tagihan,                        DataType::TYPE_NUMERIC],
                'I' => [(float) $inv->total_pembayaran,                     DataType::TYPE_NUMERIC],
                'J' => [(float) $inv->sisa_tagihan,                         DataType::TYPE_NUMERIC],
                'K' => [$inv->status,                                       DataType::TYPE_STRING],
            ];

            foreach ($rowData as $col => [$val, $type]) {
                $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val, $type);
            }

            foreach (['F', 'G', 'H', 'I', 'J'] as $c) {
                $sheet->getStyle("{$c}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
            }

            $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);

            $statusColor = $statusColors[$inv->status] ?? 'FF000000';
            $sheet->getStyle("K{$rowNum}")->getFont()
                ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($statusColor))
                ->setBold(true);

            $sheet->getRowDimension($rowNum)->setRowHeight(18);
            $rowNum++;
        }

        if ($rowNum > 5) {
            $sheet->getStyle("A4:{$lastCol}" . ($rowNum - 1))->applyFromArray([
                'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF1565C0']]],
            ]);
        }

        $sheet->freezePane('A5');

        // â”€â”€â”€ Sheet 2: Data Detail Tagihan Invoice â”€â”€â”€
        // Pakai invoice_id dari $invoices (sudah lewat scope + filter yang sama)
        // supaya sheet detail selalu konsisten dengan sheet utama.
        $details = InvoiceItem::with(['invoice.klienAr.resto.perusahaan', 'invoice.perusahaan', 'invoice.resto', 'barang'])
            ->whereIn('invoice_id', $invoiceIds)
            ->orderBy('invoice_id')
            ->orderBy('id')
            ->get();

        $sheet2   = $spreadsheet->createSheet();
        $sheet2->setTitle('Data Detail Tagihan Invoice');

        $cols2   = [
            'A' => ['NOMOR INVOICE',   28],
            'B' => ['Kode Barang',     16],
            'C' => ['Nama Barang',     36],
            'D' => ['Satuan',          12],
            'E' => ['QTY',             10],
            'F' => ['Harga Satuan',    18],
            'G' => ['TOTAL',           18],
            'H' => ['Stokis',          28],
            'I' => ['Kode Resto',      16],
            'J' => ['Nama Klien',      32],
            'K' => ['Entitas',         24],
            'L' => ['NOMOR INVOICE 2', 28],
        ];
        $lastCol2 = 'L';

        $sheet2->mergeCells("A1:{$lastCol2}1");
        $sheet2->setCellValue('A1', 'DATA DETAIL TAGIHAN INVOICE');
        $sheet2->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0D47A1']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet2->getRowDimension(1)->setRowHeight(36);

        $sheet2->mergeCells("A2:{$lastCol2}2");
        $sheet2->setCellValue('A2', 'Diekspor pada: ' . now()->format('d-m-Y H:i') . '   |   Total baris: ' . $details->count());
        $sheet2->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF1565C0']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE3F2FD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet2->getRowDimension(2)->setRowHeight(22);
        $sheet2->getRowDimension(3)->setRowHeight(6);

        foreach ($cols2 as $col => [$label, $width]) {
            $sheet2->setCellValue("{$col}4", $label);
            $sheet2->getColumnDimension($col)->setWidth($width);
        }
        $sheet2->getStyle("A4:{$lastCol2}4")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF0D47A1']]],
        ]);
        $sheet2->getRowDimension(4)->setRowHeight(22);

        $kodeRestoSet = collect();
        foreach ($details as $_d) {
            if ($_d->kode_resto)                               $kodeRestoSet->push($_d->kode_resto);
            if ($_d->invoice?->resto?->kode_resto)             $kodeRestoSet->push($_d->invoice->resto->kode_resto);
            if ($_d->invoice?->klienAr?->resto?->kode_resto)   $kodeRestoSet->push($_d->invoice->klienAr->resto->kode_resto);
        }
        $restosByKode = Resto::whereIn('kode_resto', $kodeRestoSet->unique()->values())->get()->keyBy('kode_resto');

        $rowNum2 = 5;
        foreach ($details as $d) {
            $inv2 = $d->invoice;
            $bg2  = $rowNum2 % 2 === 0 ? 'FFE3F2FD' : 'FFFFFFFF';

            // Prioritaskan perusahaan pemilik resto klien (B2C); fallback ke perusahaan invoice (B2B)
            $perusahaan = $inv2?->klienAr?->resto?->perusahaan ?? $inv2?->perusahaan;
            $entitas    = $perusahaan?->nama_perusahaan ?? '-';

            $resolvedKode = $d->kode_resto
                ?? $inv2?->resto?->kode_resto
                ?? $inv2?->klienAr?->resto?->kode_resto;

            $stokis = $inv2?->resto?->stokis
                ?? $inv2?->klienAr?->resto?->stokis
                ?? ($resolvedKode ? $restosByKode->get($resolvedKode)?->stokis : null)
                ?? '-';

            $kodeResto = $d->kode_resto
                ?? $inv2?->resto?->kode_resto
                ?? $inv2?->klienAr?->resto?->kode_resto
                ?? '-';

            $nomorInvoice = $d->no_invoice_resto ?? $inv2?->no_invoice ?? '-';

            $rowData2 = [
                'A' => [$nomorInvoice,                                              DataType::TYPE_STRING],
                'B' => [$d->kode_barang ?? $d->barang?->kode_barang          ?? '-', DataType::TYPE_STRING],
                'C' => [$d->nama_barang,                                            DataType::TYPE_STRING],
                'D' => [$d->satuan                                           ?? '-', DataType::TYPE_STRING],
                'E' => [(float) $d->qty,                                            DataType::TYPE_NUMERIC],
                'F' => [(float) $d->harga_satuan,                                   DataType::TYPE_NUMERIC],
                'G' => [(float) $d->subtotal,                                       DataType::TYPE_NUMERIC],
                'H' => [$stokis,                                                    DataType::TYPE_STRING],
                'I' => [$kodeResto,                                                 DataType::TYPE_STRING],
                'J' => [$inv2?->klienAr?->nama_klien                        ?? '-', DataType::TYPE_STRING],
                'K' => [$entitas,                                                   DataType::TYPE_STRING],
                'L' => [$inv2?->no_invoice                                  ?? '-', DataType::TYPE_STRING],
            ];

            foreach ($rowData2 as $col => [$val, $type]) {
                $sheet2->getCell("{$col}{$rowNum2}")->setValueExplicit($val, $type);
            }
            foreach (['E', 'F', 'G'] as $numCol) {
                $sheet2->getStyle("{$numCol}{$rowNum2}")->getNumberFormat()->setFormatCode('#,##0');
            }
            $sheet2->getStyle("A{$rowNum2}:{$lastCol2}{$rowNum2}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg2]],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet2->getRowDimension($rowNum2)->setRowHeight(18);
            $rowNum2++;
        }

        if ($rowNum2 > 5) {
            $sheet2->getStyle("A4:{$lastCol2}" . ($rowNum2 - 1))->applyFromArray([
                'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF1565C0']]],
            ]);
        }

        $sheet2->freezePane('A5');
        $spreadsheet->setActiveSheetIndex(0);

        $temp = tempnam(sys_get_temp_dir(), 'export_invoice_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'Data Tagihan Invoice-' . now()->format('Ymd-His') . '.xlsx', [
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
            'klienAr.karyawanAr',
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

        $signatureData = $this->buildSignatureData($invoice);
        $filename = 'Invoice-' . str_replace(['/', '\\', ' '], '-', $invoice->no_invoice) . '.pdf';

        return Pdf::loadView('finance.invoice-print', compact('invoice', 'signatureData', 'regularInvoicesInPeriod'))
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => false,
                'defaultFont'          => 'Arial',
                'dpi'                  => 150,
            ])
            ->stream($filename);
    }

    public function print(Request $request, int $id): Response|string
    {
        $invoice = $this->service->findOrFail($id);
        abort_if(
            $invoice->requiresApproval() && !$invoice->isApprovedForFinanceFlow(),
            422,
            'Opening balance belum disetujui, dokumen belum dapat dicetak'
        );

        $invoice->load([
            'klienAr.karyawanAr',
            'klienAr.perusahaan',
            'klienAr.resto.investor',
            'perusahaan',
            'karyawan.perusahaan',
            'resto',
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
                'klienAr.karyawanAr',
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
                'dpi'                  => 150,
            ])
            ->stream($filename);
    }

    public function syncGdrive(Invoice $invoice): JsonResponse
    {
        UploadInvoiceToGDriveJob::dispatch($invoice->id);
        return $this->successResponse(null, 'PDF sedang diupload ulang ke Google Drive');
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
}
