<?php

namespace App\Domain\Finance\Invoice\Controllers;

use App\Domain\Finance\Invoice\DTO\InvoiceDTO;
use App\Domain\Finance\Invoice\Jobs\UploadInvoiceToGDriveJob;
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
        $request->validate(['klien_ar_id' => ['required', 'integer', 'exists:tb_klien_ar,id']]);
        $carryover = $this->service->getCarryover((int) $request->klien_ar_id);
        return $this->successResponse(['carryover' => $carryover]);
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
        ]);

        $klien = KlienAr::with('perusahaan')->findOrFail((int) $payload['klien_ar_id']);

        return $this->successResponse([
            'no_invoice' => $this->service->generateConsolidatedInvoiceNo($klien),
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
            'H' => ['Stokis',           28],
            'I' => ['Tanggal Kirim',   16],
            'J' => ['Kode Resto',      16],
            'K' => ['Nama Klien',      32],
            'L' => ['Entitas',         24],
            'M' => ['NOMOR INVOICE 2', 28],
        ];
        $lastCol2 = 'M';

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
                'B' => [$d->barang?->kode_barang                             ?? '-', DataType::TYPE_STRING],
                'C' => [$d->nama_barang,                                            DataType::TYPE_STRING],
                'D' => [$d->satuan                                           ?? '-', DataType::TYPE_STRING],
                'E' => [(float) $d->qty,                                            DataType::TYPE_NUMERIC],
                'F' => [(float) $d->harga_satuan,                                   DataType::TYPE_NUMERIC],
                'G' => [(float) $d->subtotal,                                       DataType::TYPE_NUMERIC],
                'H' => [$stokis,                                                    DataType::TYPE_STRING],
                'I' => [$inv2?->tanggal_kirim_barang?->format('d-m-Y')      ?? '-', DataType::TYPE_STRING],
                'J' => [$kodeResto,                                                 DataType::TYPE_STRING],
                'K' => [$inv2?->klienAr?->nama_klien                        ?? '-', DataType::TYPE_STRING],
                'L' => [$entitas,                                                   DataType::TYPE_STRING],
                'M' => [$inv2?->no_invoice                                  ?? '-', DataType::TYPE_STRING],
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

    public function import(Request $request): JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:2048'],
            'type' => ['nullable', 'in:b2b,b2c'],
        ]);

        $type      = $request->input('type', 'b2c');
        $file      = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $path      = $file->getRealPath();
        $isCsv     = in_array($extension, ['csv', 'txt']);

        $user = auth()->user()->load('karyawan');
        abort_if(!$user?->karyawan?->id, 422, 'User tidak terhubung dengan data karyawan');

        if ($isCsv) {
            $rows1 = $this->invoiceParseCsv($path);
            $rows2 = [];
        } else {
            $spreadsheet = IOFactory::load($path);
            if ($type === 'b2b') {
                $rows1 = $this->invoiceParseXlsxSheet($spreadsheet->getSheet(0), 'no_urut');
                $rows2 = $spreadsheet->getSheetCount() > 1
                    ? $this->invoiceParseXlsxSheet($spreadsheet->getSheet(1), 'no_urut_invoice')
                    : [];
            } else {
                $rows1 = $this->invoiceParseXlsxSheet($spreadsheet->getSheet(0), 'no_urut');
                $rows2 = $spreadsheet->getSheetCount() > 1
                    ? $this->invoiceParseXlsxSheet($spreadsheet->getSheet(1), 'no_urut_invoice')
                    : [];
            }
        }

        if ($type === 'b2b') {
            return $this->importB2B($rows1, $rows2, $user);
        }

        return $this->importB2C($rows1, $rows2, $user);
    }

    private function importB2B(array $rows1, array $rows2, $user): JsonResponse
    {
        $insertedCount     = 0;
        $totalData         = 0;
        $errors            = [];
        $invoiceMapping    = [];
        $invoicesWithItems = [];

        // ── Pass 1: Buat invoice dari Sheet 1 "Data Invoice" ──
        $lineNumber = 0;
        foreach ($rows1 as $row) {
            $lineNumber++;
            $firstCell = trim((string) ($row[0] ?? ''));

            if (str_starts_with($firstCell, '#')) continue;
            if (str_starts_with($firstCell, '[CONTOH]')) continue;
            if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) continue;

            $noUrut       = $this->invoiceImportStr($row[0] ?? '');
            $namaKlien    = $this->invoiceImportStr($row[1] ?? '');
            $tanggalKirim = $this->invoiceImportDate($row[2] ?? '');
            $noSuratJalan = $this->invoiceImportStr($row[3] ?? '');
            $jatuhTempo   = $this->invoiceImportDate($row[4] ?? '');
            $periodeAwal  = $this->invoiceImportDate($row[5] ?? '');
            $periodeAkhir = $this->invoiceImportDate($row[6] ?? '');

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

            $klien = KlienAr::with('perusahaan')->where('nama_klien', $namaKlien)->first();
            if (!$klien) {
                $errors[] = ['sheet' => 'Data Invoice', 'row' => $lineNumber, 'message' => "Klien '{$namaKlien}' tidak ditemukan di sistem"];
                continue;
            }

            $noInvKons = $this->service->generateConsolidatedInvoiceNo($klien);

            if (!$periodeAwal)  $periodeAwal  = Carbon::parse($tanggalKirim)->startOfMonth()->format('Y-m-d');
            if (!$periodeAkhir) $periodeAkhir = Carbon::parse($tanggalKirim)->endOfMonth()->format('Y-m-d');

            $carryover = $this->service->getCarryover($klien->id, true);

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
                    'karyawan_id'                => $this->service->resolveInvoiceKaryawanId($user, $klien),
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

        // ── Pass 2: Buat item dari Sheet 2 "Item Invoice" ──
        $lineNumber2 = 0;
        foreach ($rows2 as $row) {
            $lineNumber2++;
            $firstCell = trim((string) ($row[0] ?? ''));

            if (str_starts_with($firstCell, '#')) continue;
            if (str_starts_with($firstCell, '[CONTOH]')) continue;
            if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) continue;

            $noUrutInvoice = $this->invoiceImportStr($row[0] ?? '');
            $noInvResto    = $this->invoiceImportStr($row[1] ?? '');
            $kodeResto     = $this->invoiceImportStr($row[2] ?? '');
            $namaResto     = $this->invoiceImportStr($row[3] ?? '');
            $kodeBarang    = $this->invoiceImportStr($row[4] ?? '');
            $namaBarang    = $this->invoiceImportStr($row[5] ?? '');
            $qty           = $this->invoiceImportNum($row[6] ?? '');
            $satuan        = $this->invoiceImportStr($row[7] ?? '');
            $harga         = $this->invoiceImportNum($row[8] ?? '');

            if (!$noUrutInvoice) {
                $errors[] = ['sheet' => 'Item Invoice', 'row' => $lineNumber2, 'message' => 'no_urut_invoice wajib diisi'];
                continue;
            }
            if (!isset($invoiceMapping[$noUrutInvoice])) {
                $errors[] = ['sheet' => 'Item Invoice', 'row' => $lineNumber2, 'message' => "no_urut_invoice '{$noUrutInvoice}' tidak ditemukan di Sheet 'Data Invoice'"];
                continue;
            }
            if (!$namaBarang) {
                $errors[] = ['sheet' => 'Item Invoice', 'row' => $lineNumber2, 'message' => 'nama_barang wajib diisi'];
                continue;
            }
            if ($qty <= 0) {
                $errors[] = ['sheet' => 'Item Invoice', 'row' => $lineNumber2, 'message' => 'qty harus lebih dari 0'];
                continue;
            }

            $invoice  = $invoiceMapping[$noUrutInvoice];
            $barangId = null;
            if ($kodeBarang) {
                $barang = \App\Models\Barang::where('kode_barang', $kodeBarang)->first();
                if ($barang) $barangId = $barang->id;
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

        // ── Post-process: Hitung subtotal untuk invoice yang punya item ──
        foreach ($invoiceMapping as $noUrut => $invoice) {
            if (isset($invoicesWithItems[$noUrut])) {
                $subtotal     = (float) $invoice->items()->sum('subtotal');
                $tagihanSblm  = (float) $invoice->tagihan_periode_sebelumnya;
                $totalTagihan = $subtotal + $tagihanSblm;
                $invoice->update([
                    'subtotal'      => $subtotal,
                    'total_tagihan' => $totalTagihan,
                    'sisa_tagihan'  => $totalTagihan,
                    'updated_by'    => auth()->id(),
                ]);
            }
            UploadInvoiceToGDriveJob::dispatch($invoice->id);
        }

        // ── Cascade carryover: propagasi tagihan_sebelumnya antar invoice dalam batch ──
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
        foreach ($firstByKlien as $firstInvoice) {
            $this->service->propagateCarryover($firstInvoice->fresh());
        }

        $failed = $totalData - $insertedCount;

        return $this->successResponse([
            'total'    => $totalData,
            'inserted' => $insertedCount,
            'failed'   => $failed,
            'errors'   => $errors,
        ], "Import B2B selesai. {$insertedCount} invoice konsolidasi ditambahkan, {$failed} gagal.");
    }

    private function importB2C(array $rows1, array $rows2, $user): JsonResponse
    {
        $insertedCount  = 0;
        $totalData      = 0;
        $errors         = [];
        $invoiceMapping = [];

        // ── Pass 1: Invoice headers ──────────────────────────────────
        $lineNumber    = 0;
        $headerSkipped = false;

        foreach ($rows1 as $row) {
            $lineNumber++;
            $firstCell = trim((string) ($row[0] ?? ''));

            if (str_starts_with($firstCell, '#')) continue;
            if (!$headerSkipped) { $headerSkipped = true; continue; }
            if (str_starts_with($firstCell, '[CONTOH]')) continue;
            if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) continue;

            $totalData++;
            $noUrut       = $this->invoiceImportStr($row[0] ?? '');   // A
            $namaKlien    = $this->invoiceImportStr($row[1] ?? '');   // B
            $tanggal      = $this->invoiceImportDate($row[2] ?? '');  // C: tanggal_invoice
            $tanggalKirim = $this->invoiceImportDate($row[3] ?? '');  // D: tanggal_kirim_barang
            $jatuhTempo   = $this->invoiceImportDate($row[4] ?? '');  // E: tanggal_jatuh_tempo
            $periodeAwal  = $this->invoiceImportDate($row[5] ?? '');  // F: periode_awal
            $periodeAkhir = $this->invoiceImportDate($row[6] ?? '');  // G: periode_akhir
            $noSuratJalan = $this->invoiceImportStr($row[7] ?? '');   // H: no_surat_jalan
            $keterangan   = $this->invoiceImportStr($row[8] ?? '');   // I: keterangan

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

            $klien = KlienAr::with('perusahaan')->where('nama_klien', $namaKlien)->first();
            if (!$klien) {
                $errors[] = ['sheet' => 'Invoice', 'row' => $lineNumber, 'message' => "Klien '{$namaKlien}' tidak ditemukan di sistem"];
                continue;
            }

            $noInvoice = $this->service->generateNoInvoice($klien, $tanggal);
            $carryover = $this->service->getCarryover($klien->id);

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
                    'karyawan_id'                => $this->service->resolveInvoiceKaryawanId($user, $klien),
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

        // ── Pass 2: Invoice items ────────────────────────────────────
        $invoicesWithItems = [];
        $lineNumber        = 0;
        $headerSkipped     = false;

        foreach ($rows2 as $row) {
            $lineNumber++;
            $firstCell = trim((string) ($row[0] ?? ''));

            if (str_starts_with($firstCell, '#')) continue;
            if (!$headerSkipped) { $headerSkipped = true; continue; }
            if (str_starts_with($firstCell, '[CONTOH]')) continue;
            if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) continue;

            $noUrutInvoice = $this->invoiceImportStr($row[0] ?? '');
            $kodeBarang    = $this->invoiceImportStr($row[1] ?? '');
            $namaBarang    = $this->invoiceImportStr($row[2] ?? '');
            $qty           = $this->invoiceImportNum($row[3] ?? '');
            $satuan        = $this->invoiceImportStr($row[4] ?? '');
            $hargaSatuan   = $this->invoiceImportNum($row[5] ?? '');

            if (!$noUrutInvoice) {
                $errors[] = ['sheet' => 'Item Invoice', 'row' => $lineNumber, 'message' => 'no_urut_invoice wajib diisi'];
                continue;
            }
            if (!isset($invoiceMapping[$noUrutInvoice])) {
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
                $barang = \App\Models\Barang::where('kode_barang', $kodeBarang)->first();
                if ($barang) {
                    $barangId = $barang->id;
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

        foreach ($invoicesWithItems as $invoice) {
            $subtotal     = (float) $invoice->items()->sum('subtotal');
            $totalTagihan = $subtotal + (float) $invoice->tagihan_periode_sebelumnya;
            $invoice->update([
                'subtotal'      => $subtotal,
                'total_tagihan' => $totalTagihan,
                'sisa_tagihan'  => $totalTagihan,
                'updated_by'    => auth()->id(),
            ]);
        }

        foreach ($invoiceMapping as $invoice) {
            UploadInvoiceToGDriveJob::dispatch($invoice->id);
        }

        // ── Cascade carryover: propagasi tagihan_sebelumnya antar invoice dalam batch ──
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
        foreach ($firstByKlien as $firstInvoice) {
            $this->service->propagateCarryover($firstInvoice->fresh());
        }

        $failed = $totalData - $insertedCount;

        return $this->successResponse([
            'total'    => $totalData,
            'inserted' => $insertedCount,
            'failed'   => $failed,
            'errors'   => $errors,
        ], "Import selesai. {$insertedCount} ditambahkan, {$failed} gagal.");
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
            'items.barang',
            'openingBalanceDetails.items.barang',
            'pembayarans',
            'createdBy.karyawan',
            'submittedBy.karyawan',
            'approvedBy.karyawan',
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

    private function buildInvoiceDataSheet(Worksheet $sheet, string $type = 'b2c'): void
    {
        $isB2B = $type === 'b2b';
        $sheet->setTitle('Invoice');
        $cols = [
            'A' => ['no_urut',              12],
            'B' => ['nama_klien *',         32],
            'C' => ['tanggal_invoice *',    20],
            'D' => ['tanggal_kirim_barang', 22],
            'E' => ['tanggal_jatuh_tempo',  20],
            'F' => ['periode_awal',         20],
            'G' => ['periode_akhir',        20],
            'H' => ['no_surat_jalan',       22],
            'I' => ['keterangan',           32],
        ];
        $lastCol = 'I';

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
            'E' => '',
            'F' => date('01-m-Y'),
            'G' => date('t-m-Y'),
            'H' => 'SJ-001',
            'I' => 'Invoice bulan ini',
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
            foreach (['C', 'D', 'E', 'F', 'G'] as $dateCol) {
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
            '7. Format tanggal: DD-MM-YYYY (contoh: 01-06-2025). Berlaku untuk tanggal_invoice, tanggal_jatuh_tempo, periode_awal, dan periode_akhir.',
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
            'A' => ['no_urut',                12],
            'B' => ['nama_klien *',           30],
            'C' => ['tanggal_kirim_barang *', 22],
            'D' => ['no_surat_jalan',         22],
            'E' => ['tanggal_jatuh_tempo',    22],
            'F' => ['periode_awal',           20],
            'G' => ['periode_akhir',          20],
        ];
        $lastCol = 'G';

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
            ['[CONTOH] 1', 'PT. Setya Kuliner Mandiri', date('01-m-Y'), '', '', date('01-m-Y'), date('t-m-Y')],
            ['[CONTOH] 2', 'PT. Arkhan Berkah Bersama', date('01-m-Y'), '', '', date('01-m-Y'), date('t-m-Y')],
        ];
        $exampleColKeys = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
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
            foreach (['C', 'E', 'F', 'G'] as $dateCol) {
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
            '6. Kolom tanggal_kirim_barang WAJIB diisi dengan format DD-MM-YYYY (contoh: 01-05-2026).',
            '7. Kolom nama_klien harus persis sesuai dengan data klien PT yang terdaftar di sistem.',
            '8. Kolom periode_awal dan periode_akhir opsional — jika kosong, sistem otomatis isi awal/akhir bulan tanggal_kirim.',
            '9. Setiap baris di Sheet "Item Invoice" menjadi satu baris Item Tagihan di sistem.',
            '10. Hapus baris [CONTOH] sebelum upload. Di halaman Import, pilih jenis "B2B Konsolidasi".',
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
            ['  KETERANGAN KOLOM — SHEET 1: DATA INVOICE (A–H)', 'FF1565C0', [
                ['no_urut',               'Nomor urut baris — kunci penghubung ke Sheet "Item Invoice"',  'Ya',       'Angka unik per baris. Contoh: 1, 2, 3'],
                ['no_invoice_konsolidasi','Nomor invoice konsolidasi ke klien PT',                        'Ya',       'Harus unik di sistem. Contoh: ABBINV-46143'],
                ['nama_klien',            'Nama Klien PT sesuai data di sistem',                          'Ya',       'Harus persis sesuai. Contoh: PT. Arkhan Berkah Bersama'],
                ['tanggal_kirim_barang',  'Tanggal pengiriman barang (menjadi tanggal invoice)',           'Ya',       'Format DD-MM-YYYY. Contoh: 01-05-2026'],
                ['no_surat_jalan',        'Nomor surat jalan pengiriman',                                 'Opsional', 'Contoh: SJ-20260601-001. Kosongkan jika tidak ada'],
                ['tanggal_jatuh_tempo',   'Tanggal jatuh tempo pembayaran',                               'Opsional', 'Format DD-MM-YYYY. Kosongkan jika tidak ada'],
                ['periode_awal',          'Awal periode tagihan (default: awal bulan tanggal_kirim)',      'Opsional', 'Format DD-MM-YYYY. Contoh: 01-05-2026'],
                ['periode_akhir',         'Akhir periode tagihan (default: akhir bulan tanggal_kirim)',    'Opsional', 'Format DD-MM-YYYY. Contoh: 31-05-2026'],
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
                'B' => [$d->barang?->kode_barang      ?? '-',                         DataType::TYPE_STRING],
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
            ['periode_awal',               'Tanggal awal periode tagihan',                                            'Ya',       'Format DD-MM-YYYY. Contoh: 01-06-2025'],
            ['periode_akhir',              'Tanggal akhir periode tagihan',                                           'Ya',       'Format DD-MM-YYYY. Contoh: 30-06-2025'],
            ['no_surat_jalan',             'Nomor surat jalan',                                                                          'Opsional', 'Teks bebas. Contoh: SJ-001/VI/2025'],
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

    private function invoiceParseXlsxSheet(Worksheet $sheet, string $firstHeader): array
    {
        $rows        = [];
        $headerFound = false;

        foreach ($sheet->getRowIterator() as $rowObj) {
            $cellIter = $rowObj->getCellIterator();
            $cellIter->setIterateOnlyExistingCells(false);

            $cells = [];
            foreach ($cellIter as $cell) {
                $cells[] = $this->invoiceXlsxCellStr($cell);
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

    private function invoiceParseCsv(string $path): array
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

    private function invoiceXlsxCellStr(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): string
    {
        $value = $cell->getValue();
        if ($value === null) return '';
        if (is_bool($value)) return $value ? '1' : '0';

        // Jika cell berformat tanggal Excel, konversi serial number → Y-m-d
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

    private function invoiceImportStr(mixed $val): ?string
    {
        $s = trim((string) $val);
        return ($s === '' || $s === '-') ? null : $s;
    }

    private function invoiceImportDate(mixed $val): ?string
    {
        $s = trim((string) $val);
        if ($s === '' || $s === '-') return null;

        // Sudah format Y-m-d
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;

        // Format DD/MM/YYYY atau DD-MM-YYYY (umum di Indonesia)
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        // Fallback: biarkan validator menolak jika format tidak dikenali
        return $s;
    }

    private function invoiceImportNum(mixed $val): float
    {
        $s = trim((string) $val);
        $s = str_replace(['.', ','], ['', '.'], $s);
        return is_numeric($s) ? (float) $s : 0.0;
    }

    private function buildSignatureData($invoice): array
    {
        if ($invoice->is_opening_balance) {
            $preparedByUser = $invoice->submittedBy ?: $invoice->createdBy;
            $preparedByName = $preparedByUser?->karyawan?->nama_karyawan
                ?? $preparedByUser?->username
                ?? '___________________';

            $approvedByName = $invoice->approvedBy?->karyawan?->nama_karyawan
                ?? $invoice->approvedBy?->username
                ?? '___________________';

            $preparedPayload = SignatureBarcodeHelper::buildObPreparedPayload($invoice, $preparedByName);
            $approvedPayload = SignatureBarcodeHelper::buildObApprovedPayload($invoice, $preparedByName);
        } else {
            $preparedByName = $invoice->klienAr?->karyawanAr?->nama_karyawan ?? '___________________';
            $approvedByName = 'Agung Tribuwono';

            $preparedPayload = SignatureBarcodeHelper::buildInvoicePreparedPayload($invoice, $preparedByName);
            $approvedPayload = SignatureBarcodeHelper::buildInvoiceApprovedPayload($invoice, $preparedByName);
        }

        return [
            'prepared_by_name' => $preparedByName,
            'prepared_qr_src'  => SignatureBarcodeHelper::generateDataUri($preparedPayload, 250),
            'approved_by_name' => $approvedByName,
            'approved_qr_src'  => SignatureBarcodeHelper::generateDataUri($approvedPayload, 250),
        ];
    }
}
