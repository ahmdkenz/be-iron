<?php

namespace App\Domain\Finance\Invoice\Controllers;

use App\Domain\Finance\Invoice\DTO\InvoiceDTO;
use App\Domain\Finance\Invoice\Jobs\ImportInvoiceJob;
use App\Domain\Finance\Invoice\Jobs\UploadInvoiceToGDriveJob;
use App\Models\InvoiceImportBatch;
use App\Domain\Finance\Invoice\Requests\StoreInvoiceRequest;
use App\Domain\Finance\Invoice\Requests\UpdateInvoiceRequest;
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
use PhpOffice\PhpSpreadsheet\IOFactory;
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
        $user    = auth()->user();
        $filters = $request->only([
            'search', 'status', 'klien_ar_id', 'karyawan_id',
            'tanggal_dari', 'tanggal_sampai', 'segment',
        ]);
        $filters['is_opening_balance'] = false;
        ArFilterScope::apply($filters, $user);

        $list = $this->service->paginate($filters);
        return $this->paginatedResponse(
            $list->through(fn($inv) => new InvoiceResource($inv))
        );
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
        $filters = $request->only(['klien_ar_id', 'periode_bulan', 'periode_tahun']);
        ArFilterScope::apply($filters, $user);

        return $this->successResponse($this->service->getRekapKlien($filters));
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
        $invoice = $this->service->findOrFail($id);
        return $this->successResponse(new InvoiceResource($invoice));
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

        $invoices = $this->service->paginate(array_merge($filters, ['per_page' => 9999]))->items();

        $headers = [
            'No Invoice', 'Klien', 'Resto', 'Perusahaan', 'Tanggal Invoice',
            'Periode Awal', 'Periode Akhir', 'Subtotal', 'Tagihan Sebelumnya',
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
                    $inv->periode_awal?->format('d-m-Y'),
                    $inv->periode_akhir?->format('d-m-Y'),
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

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Tagihan Invoice');

        $cols = [
            'A' => ['No Invoice',             24],
            'B' => ['Klien',                  32],
            'C' => ['Resto',                  28],
            'D' => ['Entitas',               28],
            'E' => ['Tanggal Invoice',        18],
            'F' => ['Periode Awal',           16],
            'G' => ['Periode Akhir',          16],
            'H' => ['Subtotal',               18],
            'I' => ['Tagihan Sebelumnya',     22],
            'J' => ['Total Tagihan',          18],
            'K' => ['Total Pembayaran',       20],
            'L' => ['Sisa Tagihan',           18],
            'M' => ['Status',                 14],
        ];
        $lastCol = 'M';

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
                'F' => [$inv->periode_awal?->format('d-m-Y') ?? '-',       DataType::TYPE_STRING],
                'G' => [$inv->periode_akhir?->format('d-m-Y') ?? '-',      DataType::TYPE_STRING],
                'H' => [(float) $inv->subtotal,                             DataType::TYPE_NUMERIC],
                'I' => [(float) $inv->tagihan_periode_sebelumnya,           DataType::TYPE_NUMERIC],
                'J' => [(float) $inv->total_tagihan,                        DataType::TYPE_NUMERIC],
                'K' => [(float) $inv->total_pembayaran,                     DataType::TYPE_NUMERIC],
                'L' => [(float) $inv->sisa_tagihan,                         DataType::TYPE_NUMERIC],
                'M' => [$inv->status,                                       DataType::TYPE_STRING],
            ];

            foreach ($rowData as $col => [$val, $type]) {
                $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val, $type);
            }

            foreach (['H', 'I', 'J', 'K', 'L'] as $c) {
                $sheet->getStyle("{$c}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
            }

            $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);

            $statusColor = $statusColors[$inv->status] ?? 'FF000000';
            $sheet->getStyle("M{$rowNum}")->getFont()
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

        // ─── Sheet 2: Data Detail Tagihan Invoice ───
        $details = InvoiceItem::with(['invoice.klienAr.resto.perusahaan', 'invoice.perusahaan', 'invoice.resto', 'barang'])
            ->whereHas('invoice', function ($q) use ($filters) {
                $q->where('is_opening_balance', false)
                  ->when($filters['tanggal_dari'] ?? null, fn($q, $v) => $q->where('tanggal_invoice', '>=', $v))
                  ->when($filters['tanggal_sampai'] ?? null, fn($q, $v) => $q->where('tanggal_invoice', '<=', $v))
                  ->when($filters['klien_ar_id'] ?? null, fn($q, $v) => $q->where('klien_ar_id', $v))
                  ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('perusahaan_id', $v));
            })
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

    public function importTemplate(Request $request): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $type = in_array($request->query('type'), ['b2b', 'b2c']) ? $request->query('type') : 'b2c';

        $spreadsheet = new Spreadsheet();

        if ($type === 'b2b') {
            $this->buildB2BDataSheet($spreadsheet->getActiveSheet());
            $this->buildB2BItemSheet($spreadsheet->createSheet());
            $this->buildB2BInstructionSheet($spreadsheet->createSheet());
        } else {
            $this->buildInvoiceDataSheet($spreadsheet->getActiveSheet(), $type);
            $this->buildInvoiceItemSheet($spreadsheet->createSheet());
            $this->buildInvoiceInstructionSheet($spreadsheet->createSheet(), $type);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $temp     = tempnam(sys_get_temp_dir(), 'tpl_invoice_') . '.xlsx';
        $filename = $type === 'b2b' ? 'Template Tagihan Invoice B2B Konsolidasi.xlsx' : 'Template Tagihan Invoice B2C.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    /**
     * Terima file import lalu proses di latar belakang (queue).
     * Mengembalikan batch_id yang dipakai frontend untuk polling progress.
     */
    public function import(Request $request): JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
            'type' => ['nullable', 'in:b2b,b2c'],
        ]);

        $user = auth()->user()->load('karyawan');
        abort_if(!$user?->karyawan?->id, 422, 'User tidak terhubung dengan data karyawan');

        // Bersihkan batch yang nyangkut (worker dihentikan host di tengah proses).
        InvoiceImportBatch::failStale();

        $type = $request->input('type', 'b2c');
        $path = $request->file('file')->store('invoice-imports');

        $batch = InvoiceImportBatch::create([
            'user_id'   => $user->id,
            'type'      => $type,
            'file_path' => $path,
            'status'    => 'queued',
        ]);

        ImportInvoiceJob::dispatch($batch->id);

        return $this->successResponse([
            'batch_id' => $batch->id,
            'status'   => $batch->status,
        ], 'File diterima. Import sedang diproses di latar belakang.', 202);
    }

    /**
     * Status & progress sebuah batch import (di-poll frontend).
     */
    public function importStatus(string $id): JsonResponse
    {
        // Bersihkan batch yang nyangkut (worker dihentikan host di tengah proses).
        InvoiceImportBatch::failStale();

        $batch = InvoiceImportBatch::find($id);
        if (!$batch) {
            return $this->notFoundResponse('Batch import tidak ditemukan');
        }

        $user = auth()->user();
        if ($batch->user_id !== $user->id && !RoleHelper::hasAnyRole($user, ['ADMIN', 'MANAGER', 'SUPERVISOR'])) {
            return $this->unauthorizedResponse();
        }

        return $this->successResponse([
            'batch_id'       => $batch->id,
            'status'         => $batch->status,
            'processed'      => $batch->processed,
            'progress_total' => $batch->total,
            'total'          => $batch->total_data,
            'inserted'       => $batch->inserted,
            'updated'        => $batch->updated,
            'skipped'        => $batch->skipped,
            'failed'         => $batch->failed,
            'errors'         => $batch->errors ?? [],
            'message'        => $batch->message,
        ]);
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

    private function buildInvoiceDataSheet(Worksheet $sheet, string $type = 'b2c'): void
    {
        $isB2B = $type === 'b2b';
        $sheet->setTitle('Invoice');
        $cols = [
            'A' => ['no_urut',             12],
            'B' => ['nama_klien *',        32],
            'C' => ['tanggal_invoice *',   20],
            'D' => ['tanggal_jatuh_tempo', 20],
            'E' => ['no_surat_jalan',      22],
            'F' => ['keterangan',          32],
        ];
        $lastCol = 'F';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'TEMPLATE TAGIHAN INVOICE B2C — SHEET 1: DATA INVOICE');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0D47A1']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', 'Isi data invoice. No. Invoice digenerate otomatis oleh sistem. Gunakan no_urut yang sama di Sheet "Item Invoice" untuk menghubungkan item. Lihat sheet "Petunjuk Pengisian" untuk panduan.');
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF37474F']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE3F2FD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(28);
        $sheet->getRowDimension(3)->setRowHeight(8);

        foreach ($cols as $col => [$name, $width]) {
            $sheet->setCellValue("{$col}4", $name);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF0D47A1']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(24);

        $example = [
            'A' => '[CONTOH] 1',
            'B' => 'Budi Santoso',
            'C' => date('d-m-Y'),
            'D' => '',
            'E' => 'SJ-001',
            'F' => 'Invoice bulan ini',
        ];
        foreach ($example as $col => $val) {
            $sheet->getCell("{$col}5")->setValueExplicit($val, DataType::TYPE_STRING);
        }
        $sheet->getStyle("A5:{$lastCol}5")->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FFE65100']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF9C4']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFFECB3']]],
        ]);
        $sheet->getRowDimension(5)->setRowHeight(20);

        for ($row = 6; $row <= 55; $row++) {
            $bg = $row % 2 === 0 ? 'FFF5F5F5' : 'FFFFFFFF';
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFE0E0E0']]],
            ]);
            foreach (['C', 'D'] as $dateCol) {
                $sheet->getStyle("{$dateCol}{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
            $sheet->getRowDimension($row)->setRowHeight(18);
        }

        $sheet->freezePane('A5');
        $sheet->setAutoFilter("A4:{$lastCol}4");
    }

    private function buildInvoiceItemSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Item Invoice');
        $cols = [
            'A' => ['no_urut_invoice', 18],
            'B' => ['kode_barang',     20],
            'C' => ['nama_barang',     32],
            'D' => ['qty',             12],
            'E' => ['satuan',          14],
            'F' => ['harga_satuan',    20],
        ];
        $lastCol = 'F';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'TEMPLATE IMPORT INVOICE AR — SHEET 2: ITEM INVOICE');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', 'Isi item/rincian invoice. Kolom no_urut_invoice harus sesuai dengan no_urut di Sheet "Invoice". Satu invoice bisa memiliki beberapa baris item.');
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF37474F']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8F5E9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(28);
        $sheet->getRowDimension(3)->setRowHeight(8);

        foreach ($cols as $col => [$name, $width]) {
            $sheet->setCellValue("{$col}4", $name);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E7D32']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1B5E20']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(24);

        $example = [
            'A' => '[CONTOH] 1',
            'B' => 'BRG-001',
            'C' => 'Jasa Pelayanan',
            'D' => '1',
            'E' => 'Paket',
            'F' => '500000',
        ];
        foreach ($example as $col => $val) {
            $sheet->getCell("{$col}5")->setValueExplicit($val, DataType::TYPE_STRING);
        }
        $sheet->getStyle("A5:{$lastCol}5")->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FFE65100']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF9C4']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFFECB3']]],
        ]);
        $sheet->getRowDimension(5)->setRowHeight(20);

        for ($row = 6; $row <= 105; $row++) {
            $bg = $row % 2 === 0 ? 'FFF5F5F5' : 'FFFFFFFF';
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFE0E0E0']]],
            ]);
            $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            $sheet->getRowDimension($row)->setRowHeight(18);
        }

        $sheet->freezePane('A5');
        $sheet->setAutoFilter("A4:{$lastCol}4");
    }

    private function buildInvoiceInstructionSheet(Worksheet $sheet, string $type = 'b2c'): void
    {
        $sheet->setTitle('Petunjuk Pengisian');
        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(52);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(40);

        $row = 1;

        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", $type === 'b2b'
            ? 'PETUNJUK PENGISIAN — TEMPLATE TAGIHAN INVOICE B2B'
            : 'PETUNJUK PENGISIAN — TEMPLATE TAGIHAN INVOICE B2C');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0D47A1']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(34);
        $row += 2;

        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", '  CARA PENGISIAN');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++;

        $steps = [
            '1. File memiliki 2 sheet: "Invoice" (header) dan "Item Invoice" (rincian item per invoice).',
            '2. Jangan ubah nama atau urutan kolom pada baris header (berwarna biru/hijau).',
            '3. Hapus baris [CONTOH] sebelum melakukan import.',
            '4. Sheet "Invoice": satu baris per invoice. Kolom no_urut digunakan untuk menghubungkan ke item.',
            '5. Sheet "Item Invoice": satu baris per item. no_urut_invoice harus sesuai no_urut di Sheet "Invoice".',
            '6. Satu invoice dapat memiliki lebih dari satu item (lebih dari satu baris dengan no_urut_invoice sama).',
            '7. Format tanggal: DD-MM-YYYY (contoh: 01-06-2025). Berlaku untuk tanggal_invoice dan tanggal_jatuh_tempo.',
            '8. Kolom opsional dapat dikosongkan.',
            '9. Simpan file sebagai .xlsx sebelum diupload. CSV hanya mengimpor Sheet "Invoice" tanpa item.',
        ];

        foreach ($steps as $i => $step) {
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->setCellValue("A{$row}", "  {$step}");
            $bg = $i % 2 === 0 ? 'FFFFFFFF' : 'FFF8F9FA';
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font'      => ['size' => 9],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFE0E0E0']]],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(20);
            $row++;
        }
        $row++;

        foreach ([
            ['  KETERANGAN KOLOM — SHEET "INVOICE"',      'FF1565C0', $this->getInvoiceColInfos($type)],
            ['  KETERANGAN KOLOM — SHEET "ITEM INVOICE"', 'FF2E7D32', $this->getItemColInfos()],
        ] as [$sectionTitle, $sectionColor, $colInfos]) {
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->setCellValue("A{$row}", $sectionTitle);
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $sectionColor]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(22);
            $row++;

            foreach (['A' => 'Kolom', 'B' => 'Keterangan', 'C' => 'Wajib', 'D' => 'Format / Contoh'] as $col => $label) {
                $sheet->setCellValue("{$col}{$row}", $label);
            }
            $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
                'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FFFFFFFF']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF42A5F5']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1565C0']]],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(20);
            $row++;

            foreach ($colInfos as $i => [$colName, $desc, $req, $fmt]) {
                foreach (['A' => $colName, 'B' => $desc, 'C' => $req, 'D' => $fmt] as $cellCol => $val) {
                    $sheet->setCellValue("{$cellCol}{$row}", $val);
                }
                $bg = $i % 2 === 0 ? 'FFFFFFFF' : 'FFF5F5F5';
                $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
                    'font'      => ['size' => 9],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE0E0E0']]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                ]);
                $sheet->getRowDimension($row)->setRowHeight(18);
                $row++;
            }
            $row++;
        }
    }

    private function buildB2BDataSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Data Invoice');
        $cols = [
            'A' => ['no_urut',             12],
            'B' => ['nama_klien *',        30],
            'C' => ['tanggal_invoice *',   22],
            'D' => ['no_surat_jalan',      22],
            'E' => ['tanggal_jatuh_tempo', 22],
        ];
        $lastCol = 'E';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'TEMPLATE TAGIHAN INVOICE B2B KONSOLIDASI — SHEET 1: DATA INVOICE');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0D47A1']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', 'Isi data invoice. No. Invoice konsolidasi digenerate otomatis oleh sistem. Gunakan no_urut yang sama di Sheet "Item Invoice" untuk menghubungkan item. Lihat sheet "Petunjuk Pengisian" untuk panduan.');
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF37474F']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE3F2FD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(28);
        $sheet->getRowDimension(3)->setRowHeight(8);

        foreach ($cols as $col => [$name, $width]) {
            $sheet->setCellValue("{$col}4", $name);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF0D47A1']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(24);

        $examples = [
            ['[CONTOH] 1', 'PT. Setya Kuliner Mandiri', date('01-m-Y'), '', ''],
            ['[CONTOH] 2', 'PT. Arkhan Berkah Bersama', date('01-m-Y'), '', ''],
        ];
        $exampleColKeys = ['A', 'B', 'C', 'D', 'E'];
        foreach ($examples as $i => $ex) {
            $rowNum = 5 + $i;
            foreach ($exampleColKeys as $j => $col) {
                $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($ex[$j], DataType::TYPE_STRING);
            }
            $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FFE65100']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF9C4']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFFECB3']]],
            ]);
            $sheet->getRowDimension($rowNum)->setRowHeight(20);
        }

        for ($row = 7; $row <= 56; $row++) {
            $bg = $row % 2 === 0 ? 'FFF5F5F5' : 'FFFFFFFF';
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFE0E0E0']]],
            ]);
            foreach (['C', 'E'] as $dateCol) {
                $sheet->getStyle("{$dateCol}{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
            $sheet->getRowDimension($row)->setRowHeight(18);
        }

        $sheet->freezePane('A5');
        $sheet->setAutoFilter("A4:{$lastCol}4");
    }

    private function buildB2BItemSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Item Invoice');
        $cols = [
            'A' => ['no_urut_invoice',  16],
            'B' => ['no_invoice_resto', 28],
            'C' => ['kode_resto',       16],
            'D' => ['nama_resto',       28],
            'E' => ['kode_barang',      16],
            'F' => ['nama_barang *',    36],
            'G' => ['qty *',            10],
            'H' => ['satuan',           12],
            'I' => ['harga_satuan *',   18],
        ];
        $lastCol = 'I';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'DATA ITEM INVOICE');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', 'Isi item kiriman per resto. Kolom no_urut_invoice harus sesuai dengan no_urut di Sheet "Data Invoice". Satu invoice bisa memiliki beberapa baris item kiriman.');
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF37474F']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8F5E9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(28);
        $sheet->getRowDimension(3)->setRowHeight(8);

        foreach ($cols as $col => [$name, $width]) {
            $sheet->setCellValue("{$col}4", $name);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E7D32']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1B5E20']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(24);

        $examples = [
            ['[CONTOH] 1', 'SI-M10218381051419419', 'M1021838', 'OT-SILIWANGI3',  '9113', 'Ayam 1.0 - 1.1 Kg', '75',  'Ekr', '44300'],
            ['[CONTOH] 1', 'SI-M10218381051419419', 'M1021838', 'OT-SILIWANGI3',  '9131', 'Minced Beef 1',      '50',  'Ktg', '34628'],
            ['[CONTOH] 2', 'SI-M20210981051419888', 'M2021098', 'OT-CIHAMPELAS2', '9113', 'Ayam 1.0 - 1.1 Kg', '120', 'Ekr', '44300'],
        ];
        $exampleColKeys = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];
        foreach ($examples as $i => $ex) {
            $rowNum = 5 + $i;
            foreach ($exampleColKeys as $j => $col) {
                $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($ex[$j], DataType::TYPE_STRING);
            }
            $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FFE65100']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF9C4']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFFECB3']]],
            ]);
            $sheet->getRowDimension($rowNum)->setRowHeight(20);
        }

        for ($row = 8; $row <= 207; $row++) {
            $bg = $row % 2 === 0 ? 'FFF5F5F5' : 'FFFFFFFF';
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFE0E0E0']]],
            ]);
            $sheet->getStyle("I{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            $sheet->getRowDimension($row)->setRowHeight(18);
        }

        $sheet->freezePane('A5');
        $sheet->setAutoFilter("A4:{$lastCol}4");
    }

    private function buildB2BInstructionSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Petunjuk Pengisian');
        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(52);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(40);

        $row = 1;

        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", 'PETUNJUK PENGISIAN — TEMPLATE TAGIHAN INVOICE B2B KONSOLIDASI');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0D47A1']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(34);
        $row += 2;

        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", '  CARA PENGISIAN');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++;

        $steps = [
            '1. File memiliki 2 sheet data: "Data Invoice" dan "Item Invoice", serta sheet "Petunjuk Pengisian".',
            '2. Sheet "Data Invoice" — satu baris = satu invoice konsolidasi ke klien PT.',
            '3. Sheet "Item Invoice" — satu baris = satu item kiriman per resto. Satu invoice bisa memiliki banyak baris item.',
            '4. Kolom no_urut di Sheet "Data Invoice" adalah kunci penghubung. Isi no_urut_invoice di Sheet "Item Invoice" dengan angka yang sama.',
            '5. Kolom no_invoice_konsolidasi WAJIB diisi — ini adalah nomor invoice konsolidasi (misal: ABBINV-46143).',
            '6. Kolom tanggal_invoice WAJIB diisi dengan format DD-MM-YYYY (contoh: 01-05-2026).',
            '7. Kolom nama_klien harus persis sesuai dengan data klien PT yang terdaftar di sistem.',
            '8. Setiap baris di Sheet "Item Invoice" menjadi satu baris Item Tagihan di sistem.',
            '9. Hapus baris [CONTOH] sebelum upload. Di halaman Import, pilih jenis "B2B Konsolidasi".',
        ];

        foreach ($steps as $i => $step) {
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->setCellValue("A{$row}", "  {$step}");
            $bg = $i % 2 === 0 ? 'FFFFFFFF' : 'FFF8F9FA';
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font'      => ['size' => 9],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFE0E0E0']]],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(20);
            $row++;
        }
        $row++;

        $sections = [
            ['  KETERANGAN KOLOM — SHEET 1: DATA INVOICE (A–E)', 'FF1565C0', [
                ['no_urut',               'Nomor urut baris — kunci penghubung ke Sheet "Item Invoice"',  'Ya',       'Angka unik per baris. Contoh: 1, 2, 3'],
                ['no_invoice_konsolidasi','Nomor invoice konsolidasi ke klien PT',                        'Ya',       'Harus unik di sistem. Contoh: ABBINV-46143'],
                ['nama_klien',            'Nama Klien PT sesuai data di sistem',                          'Ya',       'Harus persis sesuai. Contoh: PT. Arkhan Berkah Bersama'],
                ['tanggal_invoice',       'Tanggal invoice (tanggal tagihan diterbitkan)',                 'Ya',       'Format DD-MM-YYYY. Contoh: 01-05-2026'],
                ['no_surat_jalan',        'Nomor surat jalan pengiriman',                                 'Opsional', 'Contoh: SJ-20260601-001. Kosongkan jika tidak ada'],
                ['tanggal_jatuh_tempo',   'Tanggal jatuh tempo pembayaran',                               'Opsional', 'Format DD-MM-YYYY. Kosongkan jika tidak ada'],
            ]],
            ['  KETERANGAN KOLOM — SHEET 2: ITEM INVOICE (A–I)', 'FF2E7D32', [
                ['no_urut_invoice', 'Nomor urut invoice dari Sheet "Data Invoice" (kolom no_urut)',    'Ya',       'Harus sesuai no_urut di Sheet "Data Invoice". Contoh: 1'],
                ['no_invoice_resto','Nomor invoice per-resto',                                        'Opsional', 'Contoh: SI-M10218381051419419'],
                ['kode_resto',      'Kode resto penerima',                                            'Opsional', 'Contoh: M1021838'],
                ['nama_resto',      'Nama resto atau stokis',                                         'Opsional', 'Contoh: OT-SILIWANGI3'],
                ['kode_barang',     'Kode barang dari master barang di sistem',                       'Opsional', 'Contoh: 9113. Digunakan untuk link ke master barang.'],
                ['nama_barang',     'Nama barang yang ditagihkan',                                    'Ya',       'Contoh: Ayam 1.0 - 1.1 Kg'],
                ['qty',             'Jumlah/kuantitas kiriman per baris ini',                         'Ya',       'Angka positif. Contoh: 75'],
                ['satuan',          'Satuan barang',                                                  'Opsional', 'Contoh: Ekr, Ktg, Kg, bks'],
                ['harga_satuan',    'Harga per satuan barang',                                        'Ya',       'Angka tanpa format. Contoh: 44300'],
            ]],
        ];

        foreach ($sections as [$sectionTitle, $sectionColor, $colInfos]) {
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->setCellValue("A{$row}", $sectionTitle);
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $sectionColor]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(22);
            $row++;

            foreach (['A' => 'Kolom', 'B' => 'Keterangan', 'C' => 'Wajib', 'D' => 'Format / Contoh'] as $col => $label) {
                $sheet->setCellValue("{$col}{$row}", $label);
            }
            $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
                'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FFFFFFFF']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF42A5F5']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1565C0']]],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(20);
            $row++;

            foreach ($colInfos as $i => [$colName, $desc, $req, $fmt]) {
                foreach (['A' => $colName, 'B' => $desc, 'C' => $req, 'D' => $fmt] as $cellCol => $val) {
                    $sheet->setCellValue("{$cellCol}{$row}", $val);
                }
                $bg = $i % 2 === 0 ? 'FFFFFFFF' : 'FFF5F5F5';
                $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
                    'font'      => ['size' => 9],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE0E0E0']]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                ]);
                $sheet->getRowDimension($row)->setRowHeight(18);
                $row++;
            }
            $row++;
        }
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

    private function getInvoiceColInfos(string $type = 'b2c'): array
    {
        $infos = [
            ['no_urut',                    'Nomor urut baris (penghubung ke Sheet Item Invoice)',                     'Ya',       'Angka unik per baris. Contoh: 1, 2, 3'],
            ['no_invoice',                 'Nomor invoice unik',                                                      'Ya',       $type === 'b2b' ? 'Harus unik di sistem. Contoh: SI-B2B-21052026-001' : 'Harus unik di sistem. Contoh: SI-B2C-21052026-001'],
            ['nama_klien',                 'Nama Client sesuai data di sistem',                                       'Ya',       'Harus persis sesuai nama klien di sistem'],
            ['tanggal_invoice',            'Tanggal pembuatan invoice',                                               'Ya',       'Format DD-MM-YYYY. Contoh: 15-06-2025'],
            ['tanggal_jatuh_tempo',        'Tanggal jatuh tempo pembayaran invoice',                                  'Opsional', 'Format DD-MM-YYYY. Contoh: 15-07-2025. Kosongkan jika tidak ada.'],
            ['no_surat_jalan',             'Nomor surat jalan',                                                       'Opsional', 'Teks bebas. Contoh: SJ-001/VI/2025'],
            ['keterangan',                 'Catatan tambahan untuk invoice',                                                         'Opsional', 'Teks bebas'],
            ['tagihan_periode_sebelumnya', 'Saldo tagihan dari periode sebelumnya — dihitung OTOMATIS oleh sistem, tidak perlu diisi', '—',        'Otomatis dari sisa tagihan klien yang belum lunas di database'],
        ];

        if ($type === 'b2b') {
            $infos[] = ['nama_resto *', 'Nama Resto yang ditagihkan (wajib untuk klien B2B/PT)', 'Ya', 'Harus persis sesuai nama resto di sistem. Contoh: Resto Makmur'];
        }

        return $infos;
    }

    private function getItemColInfos(): array
    {
        return [
            ['no_urut_invoice', 'Nomor urut invoice dari Sheet "Invoice" (kolom no_urut)', 'Ya',       'Harus sesuai no_urut di Sheet Invoice. Contoh: 1'],
            ['kode_barang',     'Kode barang dari master barang',                           'Opsional', 'Jika diisi, barang akan dihubungkan ke master. Contoh: BRG-001'],
            ['nama_barang',     'Nama barang atau jasa yang ditagihkan',                    'Ya',       'Teks bebas. Contoh: Jasa Pelayanan Bulan Juni'],
            ['qty',             'Jumlah / kuantitas',                                       'Ya',       'Angka positif. Contoh: 1, 2.5'],
            ['satuan',          'Satuan barang atau jasa',                                  'Opsional', 'Contoh: Paket, Bulan, Unit, Kg'],
            ['harga_satuan',    'Harga per satuan',                                         'Ya',       'Angka tanpa format ribu. Contoh: 500000'],
        ];
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
