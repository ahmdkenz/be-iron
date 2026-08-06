<?php

namespace App\Domain\Finance\OpeningBalance\Controllers;

use App\Domain\Finance\Invoice\Resources\InvoiceResource;
use App\Domain\Finance\Invoice\Resources\OpeningBalanceDetailResource;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\OpeningBalance\Jobs\ProcessOpeningBalanceImportJob;
use App\Domain\Finance\OpeningBalance\Requests\StoreOpeningBalanceRequest;
use App\Domain\Finance\OpeningBalance\Services\OpeningBalanceImportTemplateService;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\KlienAr;
use App\Models\OpeningBalanceImportBatch;
use App\Support\Helpers\ArFilterScope;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OpeningBalanceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly InvoiceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeViewOpeningBalance();

        $user = auth()->user();
        $filters = $request->only([
            'search', 'status', 'klien_ar_id', 'karyawan_id',
            'tanggal_dari', 'tanggal_sampai', 'approval_status', 'per_page', 'segment',
        ]);
        $filters['is_opening_balance'] = true;
        ArFilterScope::apply($filters, $user);

        $list = $this->service->paginate($filters, with: [
            'klienAr' => fn ($q) => $q->withTrashed(),
            'resto', 'perusahaan', 'karyawan.perusahaan',
            'submittedBy', 'createdBy', 'updatedBy',
        ]);

        return $this->paginatedResponse(
            $list->through(fn ($invoice) => new InvoiceResource($invoice))
        );
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorizeViewOpeningBalance();

        $user = auth()->user();
        $filters = $request->only([
            'search', 'status', 'klien_ar_id', 'karyawan_id',
            'tanggal_dari', 'tanggal_sampai', 'approval_status', 'segment',
        ]);
        $filters['is_opening_balance'] = true;
        ArFilterScope::apply($filters, $user);

        return $this->successResponse($this->service->getSummary($filters));
    }

    public function store(StoreOpeningBalanceRequest $request): JsonResponse
    {
        $this->authorizeOperateOpeningBalance();
        $this->authorizeKlienArOwnership((int) $request->validated('klien_ar_id'));

        $invoice = $this->service->createOpeningBalance($request->validated());

        return $this->createdResponse(
            new InvoiceResource($invoice),
            'Opening balance berhasil diajukan untuk persetujuan'
        );
    }

    public function update(StoreOpeningBalanceRequest $request, int $id): JsonResponse
    {
        $this->authorizeOperateOpeningBalance();

        $invoice = $this->findOpeningBalanceOrFail($id);
        $this->authorizeKlienArOwnership((int) $request->validated('klien_ar_id'));

        $updated = $this->service->updateOpeningBalance($invoice, $request->validated());

        return $this->successResponse(
            new InvoiceResource($updated),
            'Opening balance berhasil diperbarui'
        );
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $this->authorizeApproveOpeningBalance();

        $payload = $request->validate([
            'note' => ['nullable', 'string'],
        ]);

        $invoice = $this->findOpeningBalanceOrFail($id);
        $updated = $this->service->approveOpeningBalance($invoice, $payload['note'] ?? null);

        Log::channel('security')->info('Opening balance disetujui', [
            'user_id' => auth()->id(),
            'invoice_id' => $invoice->id,
            'no_invoice' => $invoice->no_invoice,
            'ip' => $request->ip(),
        ]);

        return $this->successResponse(
            new InvoiceResource($updated),
            'Opening balance berhasil disetujui'
        );
    }

    public function bulkApprove(Request $request): JsonResponse
    {
        $this->authorizeApproveOpeningBalance();

        $payload = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'note' => ['nullable', 'string'],
        ]);

        $approvedIds = [];
        $failed = [];

        foreach ($payload['ids'] as $id) {
            try {
                $invoice = $this->findOpeningBalanceOrFail((int) $id);
                $this->service->approveOpeningBalance($invoice, $payload['note'] ?? null);
                $approvedIds[] = $id;
            } catch (\Throwable $e) {
                $failed[] = ['id' => $id, 'message' => $e->getMessage()];
            }
        }

        Log::channel('security')->info('Bulk approve opening balance', [
            'user_id' => auth()->id(),
            'approved_ids' => $approvedIds,
            'failed' => $failed,
            'ip' => $request->ip(),
        ]);

        return $this->successResponse(
            ['approved' => count($approvedIds), 'total' => count($payload['ids']), 'failed' => $failed],
            count($approvedIds) . ' dari ' . count($payload['ids']) . ' Opening Balance berhasil disetujui'
        );
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $this->authorizeApproveOpeningBalance();

        $payload = $request->validate([
            'note' => ['required', 'string'],
        ]);

        $invoice = $this->findOpeningBalanceOrFail($id);
        $updated = $this->service->rejectOpeningBalance($invoice, $payload['note']);

        Log::channel('security')->info('Opening balance ditolak', [
            'user_id' => auth()->id(),
            'invoice_id' => $invoice->id,
            'no_invoice' => $invoice->no_invoice,
            'note' => $payload['note'],
            'ip' => $request->ip(),
        ]);

        return $this->successResponse(
            new InvoiceResource($updated),
            'Opening balance berhasil ditolak'
        );
    }

    public function details(int $id): JsonResponse
    {
        $this->authorizeViewOpeningBalance();

        $invoice = $this->findOpeningBalanceOrFail($id);

        return $this->successResponse(
            OpeningBalanceDetailResource::collection(
                $invoice->openingBalanceDetails()->with('items.barang')->orderBy('tanggal_invoice_asal')->get()
            )
        );
    }

    public function resubmit(Request $request, int $id): JsonResponse
    {
        $this->authorizeOperateOpeningBalance();

        $payload = $request->validate([
            'note' => ['nullable', 'string'],
        ]);

        $invoice = $this->findOpeningBalanceOrFail($id);
        $updated = $this->service->resubmitOpeningBalance($invoice, $payload['note'] ?? null);

        return $this->successResponse(
            new InvoiceResource($updated),
            'Opening balance berhasil diajukan ulang'
        );
    }

    // ─── Import Master Opening Balance (tab di halaman Import Master Data) ─────

    public function importTemplate(Request $request, OpeningBalanceImportTemplateService $templates): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        if ($request->query('format') === 'xlsx') {
            if (! class_exists('ZipArchive')) {
                return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
            }

            return response()
                ->download($templates->buildXlsxFile(), 'template-import-master-opening-balance.xlsx', [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])
                ->deleteFileAfterSend(true);
        }

        return response()->streamDownload(function () use ($templates) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
            foreach ($templates->buildRows() as $row) {
                // Titik koma, bukan koma — locale Excel Indonesia pakai ";" sebagai list
                // separator sistem, sama seperti template Import Master Invoice.
                fputcsv($handle, $row, ';');
            }
            fclose($handle);
        }, 'template-import-master-opening-balance.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (! RoleHelper::hasAnyRole($user, ['ADMIN', 'MANAGER', 'SUPERVISOR'])) {
            return $this->unauthorizedResponse();
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
            'cutover_date' => ['required', 'date'],
        ]);

        OpeningBalanceImportBatch::failStale();

        if (OpeningBalanceImportBatch::active()) {
            return $this->errorResponse('Masih ada proses Import Opening Balance yang berjalan. Tunggu sampai selesai sebelum mengunggah file baru.', 409);
        }

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $path = $file->store('opening-balance-imports');

        $batch = OpeningBalanceImportBatch::create([
            'user_id' => auth()->id(),
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $path,
            'is_csv' => ! in_array($ext, ['xlsx', 'xls'], true),
            'cutover_date' => $request->input('cutover_date'),
            'status' => 'queued',
        ]);

        ProcessOpeningBalanceImportJob::dispatch($batch->id);

        return $this->successResponse([
            'batch_id' => $batch->id,
            'status' => $batch->status,
        ], 'File diterima. Import Opening Balance sedang diproses di latar belakang.', 202);
    }

    public function importActive(): JsonResponse
    {
        OpeningBalanceImportBatch::failStale();

        $batch = auth()->user() && RoleHelper::hasAnyRole(auth()->user(), ['ADMIN', 'MANAGER', 'SUPERVISOR'])
            ? OpeningBalanceImportBatch::whereIn('status', ['queued', 'processing'])->latest('created_at')->first()
            : null;

        return $this->successResponse($batch ? $this->formatImportStatus($batch) : null);
    }

    public function importStatus(string $batch): JsonResponse
    {
        OpeningBalanceImportBatch::failStale();

        $record = OpeningBalanceImportBatch::find($batch);
        if (! $record) {
            return $this->notFoundResponse('Batch import tidak ditemukan');
        }

        $user = auth()->user();
        if ($record->user_id !== $user->id && ! RoleHelper::hasAnyRole($user, ['ADMIN', 'MANAGER', 'SUPERVISOR'])) {
            return $this->unauthorizedResponse();
        }

        return $this->successResponse($this->formatImportStatus($record));
    }

    private function formatImportStatus(OpeningBalanceImportBatch $batch): array
    {
        return [
            'batch_id' => $batch->id,
            'status' => $batch->status,
            'cutover_date' => $batch->cutover_date?->format('Y-m-d'),
            'total_ob' => $batch->total_ob,
            'processed_ob' => $batch->processed_ob,
            'inserted_ob' => $batch->inserted_ob,
            'skipped_ob' => $batch->skipped_ob,
            'failed_ob' => $batch->failed_ob,
            'total_detail' => $batch->total_detail,
            'inserted_detail' => $batch->inserted_detail,
            'total_item' => $batch->total_item,
            'inserted_item' => $batch->inserted_item,
            'errors' => $batch->errors ?? [],
            'message' => $batch->message,
        ];
    }

    // ─── Export ───────────────────────────────────────────────────────────────

    public function export(Request $request): BinaryFileResponse|JsonResponse
    {
        $this->authorizeViewOpeningBalance();

        if (! class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $user = auth()->user();
        $filters = $request->only([
            'search', 'status', 'klien_ar_id', 'karyawan_id',
            'tanggal_dari', 'tanggal_sampai', 'approval_status',
        ]);
        $filters['is_opening_balance'] = true;
        ArFilterScope::apply($filters, $user);

        $records = $this->service->getAllForExport($filters);

        $spreadsheet = new Spreadsheet;

        // Sheet 1: Data Opening Balance
        $sheet1 = $spreadsheet->getActiveSheet();
        $this->buildExportObSheet($sheet1, $records);

        // Sheet 2: Rincian Invoice Asal
        $sheet2 = $spreadsheet->createSheet();
        $this->buildExportDetailSheet($sheet2, $records);

        // Sheet 3: Item Invoice Asal
        $sheet3 = $spreadsheet->createSheet();
        $this->buildExportItemSheet($sheet3, $records);

        // Sheet 4: Petunjuk
        $sheet4 = $spreadsheet->createSheet();
        $this->buildExportInfoSheet($sheet4);

        $spreadsheet->setActiveSheetIndex(0);

        $temp = tempnam(sys_get_temp_dir(), 'export_ob_').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'Data Opening Balance-'.now()->format('Ymd-His').'.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    // ─── Private: Export Sheet Builders ──────────────────────────────────────

    private function buildExportObSheet(Worksheet $sheet, $records): void
    {
        $sheet->setTitle('Data Opening Balance');

        $cols = [
            'A' => ['No. Opening Balance',  28],
            'B' => ['Klien',                30],
            'C' => ['Kode Klien',           18],
            'D' => ['Entitas Penagih',       20],
            'E' => ['Tanggal OB',           16],
            'F' => ['Saldo Awal',           20],
            'G' => ['Total Terbayar',       20],
            'H' => ['Sisa Tagihan',         20],
            'I' => ['Keterangan',           32],
            'J' => ['Status',               14],
            'K' => ['Approval',             16],
            'L' => ['Dibuat Oleh',          20],
            'M' => ['Tanggal Dibuat',       20],
        ];
        $lastCol = 'M';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'DATA OPENING BALANCE');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', 'Diekspor pada: '.now()->format('d-m-Y H:i').'   |   Total data: '.$records->count().' opening balance');
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF33691E']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF1F8E9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(22);
        $sheet->getRowDimension(3)->setRowHeight(6);

        foreach ($cols as $col => [$label, $width]) {
            $sheet->setCellValue("{$col}4", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E7D32']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1B5E20']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(22);

        $numFmt = '#,##0.00';
        $rowNum = 5;

        foreach ($records as $inv) {
            $bg = $rowNum % 2 === 0 ? 'FFF1F8E9' : 'FFFFFFFF';

            $rowData = [
                'A' => [$inv->no_invoice,                                              DataType::TYPE_STRING],
                'B' => [$inv->klienAr?->nama_klien ?? '-',                             DataType::TYPE_STRING],
                'C' => [$inv->klienAr?->kode_klien ?? '-',                             DataType::TYPE_STRING],
                'D' => [$inv->perusahaan?->nama_singkatan_perusahaan ?? '-',           DataType::TYPE_STRING],
                'E' => [$inv->tanggal_invoice ? Carbon::parse($inv->tanggal_invoice)->format('d-m-Y') : '-', DataType::TYPE_STRING],
                'F' => [(float) $inv->subtotal,         DataType::TYPE_NUMERIC],
                'G' => [(float) $inv->total_pembayaran, DataType::TYPE_NUMERIC],
                'H' => [(float) $inv->sisa_tagihan,     DataType::TYPE_NUMERIC],
                'I' => [$inv->keterangan ?? '-',                                       DataType::TYPE_STRING],
                'J' => [$inv->status ?? '-',                                           DataType::TYPE_STRING],
                'K' => [$inv->approval_status ?? '-',                                  DataType::TYPE_STRING],
                'L' => [$inv->createdBy?->username ?? '-',                             DataType::TYPE_STRING],
                'M' => [$inv->created_at ? Carbon::parse($inv->created_at)->format('d-m-Y H:i') : '-', DataType::TYPE_STRING],
            ];

            foreach ($rowData as $col => [$val, $type]) {
                $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val, $type);
            }

            foreach (['F', 'G', 'H'] as $numCol) {
                $sheet->getStyle("{$numCol}{$rowNum}")->getNumberFormat()->setFormatCode($numFmt);
            }

            $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);

            // Status color
            $statusColors = ['DRAFT' => 'FF757575', 'TERKIRIM' => 'FF1565C0', 'SEBAGIAN' => 'FFE65100', 'LUNAS' => 'FF2E7D32'];
            $statusColor = $statusColors[$inv->status] ?? 'FF212121';
            $sheet->getStyle("J{$rowNum}")->getFont()->setColor(new Color($statusColor))->setBold(true);

            // Approval color
            $approvalColors = ['PENDING' => 'FFE65100', 'APPROVED' => 'FF2E7D32', 'REJECTED' => 'FFC62828'];
            $approvalColor = $approvalColors[$inv->approval_status] ?? 'FF212121';
            $sheet->getStyle("K{$rowNum}")->getFont()->setColor(new Color($approvalColor))->setBold(true);

            $sheet->getRowDimension($rowNum)->setRowHeight(18);
            $rowNum++;
        }

        if ($rowNum > 5) {
            $sheet->getStyle("A4:{$lastCol}".($rowNum - 1))->applyFromArray([
                'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF2E7D32']]],
            ]);
        }

        $sheet->freezePane('A5');
    }

    private function buildExportDetailSheet(Worksheet $sheet, $records): void
    {
        $sheet->setTitle('Rincian Invoice Asal');

        $cols = [
            'A' => ['No. Opening Balance',  28],
            'B' => ['No. Invoice Asal',     24],
            'C' => ['Tanggal Invoice Asal', 18],
            'D' => ['Deskripsi',            32],
            'E' => ['Jumlah Tagihan Asal',  20],
            'F' => ['Sisa Tagihan Asal',    20],
            'G' => ['Keterangan',           30],
            'H' => ['Kode Resto',           16],
            'I' => ['Nama Resto',           24],
            'J' => ['Jumlah Item',          14],
        ];
        $lastCol = 'J';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'RINCIAN INVOICE ASAL');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getRowDimension(2)->setRowHeight(6);

        foreach ($cols as $col => [$label, $width]) {
            $sheet->setCellValue("{$col}3", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E7D32']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1B5E20']]],
        ]);
        $sheet->getRowDimension(3)->setRowHeight(22);

        $numFmt = '#,##0.00';
        $rowNum = 4;

        foreach ($records as $inv) {
            foreach ($inv->openingBalanceDetails as $detail) {
                $bg = $rowNum % 2 === 0 ? 'FFF1F8E9' : 'FFFFFFFF';

                $rowData = [
                    'A' => [$inv->no_invoice,                                                                         DataType::TYPE_STRING],
                    'B' => [$detail->no_invoice_asal,                                                                 DataType::TYPE_STRING],
                    'C' => [$detail->tanggal_invoice_asal ? Carbon::parse($detail->tanggal_invoice_asal)->format('d-m-Y') : '-', DataType::TYPE_STRING],
                    'D' => [$detail->deskripsi,                                                                       DataType::TYPE_STRING],
                    'E' => [(float) $detail->jumlah_tagihan_asal,                                                     DataType::TYPE_NUMERIC],
                    'F' => [(float) $detail->sisa_tagihan_asal,                                                       DataType::TYPE_NUMERIC],
                    'G' => [$detail->keterangan ?? '-',                                                               DataType::TYPE_STRING],
                    'H' => [$detail->kode_resto ?? '-',                                                               DataType::TYPE_STRING],
                    'I' => [$detail->nama_resto ?? '-',                                                               DataType::TYPE_STRING],
                    'J' => [$detail->items->count(),                                                                  DataType::TYPE_NUMERIC],
                ];

                foreach ($rowData as $col => [$val, $type]) {
                    $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val, $type);
                }

                foreach (['E', 'F'] as $numCol) {
                    $sheet->getStyle("{$numCol}{$rowNum}")->getNumberFormat()->setFormatCode($numFmt);
                }

                $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension($rowNum)->setRowHeight(18);
                $rowNum++;
            }
        }

        if ($rowNum > 4) {
            $sheet->getStyle("A3:{$lastCol}".($rowNum - 1))->applyFromArray([
                'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF2E7D32']]],
            ]);
        }

        $sheet->freezePane('A4');
    }

    private function buildExportItemSheet(Worksheet $sheet, $records): void
    {
        $sheet->setTitle('Item Invoice Asal');

        $cols = [
            'A' => ['No. Opening Balance',  28],
            'B' => ['No. Invoice Asal',     24],
            'C' => ['Kode Barang',          18],
            'D' => ['Nama Barang',          30],
            'E' => ['Qty',                  10],
            'F' => ['Satuan',               12],
            'G' => ['Harga Satuan',         20],
            'H' => ['Subtotal',             20],
            'I' => ['Keterangan',           30],
        ];
        $lastCol = 'I';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'ITEM INVOICE ASAL');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getRowDimension(2)->setRowHeight(6);

        foreach ($cols as $col => [$label, $width]) {
            $sheet->setCellValue("{$col}3", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E7D32']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1B5E20']]],
        ]);
        $sheet->getRowDimension(3)->setRowHeight(22);

        $numFmt = '#,##0.00';
        $rowNum = 4;

        foreach ($records as $inv) {
            foreach ($inv->openingBalanceDetails as $detail) {
                foreach ($detail->items as $item) {
                    $bg = $rowNum % 2 === 0 ? 'FFF1F8E9' : 'FFFFFFFF';

                    $rowData = [
                        'A' => [$inv->no_invoice,                        DataType::TYPE_STRING],
                        'B' => [$detail->no_invoice_asal,                DataType::TYPE_STRING],
                        'C' => [$item->kode_barang ?? $item->barang?->kode_barang ?? '-', DataType::TYPE_STRING],
                        'D' => [$item->nama_barang,                      DataType::TYPE_STRING],
                        'E' => [(float) $item->qty,                      DataType::TYPE_NUMERIC],
                        'F' => [$item->satuan ?? '-',                    DataType::TYPE_STRING],
                        'G' => [(float) $item->harga_satuan,             DataType::TYPE_NUMERIC],
                        'H' => [(float) $item->subtotal,                 DataType::TYPE_NUMERIC],
                        'I' => [$item->keterangan ?? '-',                DataType::TYPE_STRING],
                    ];

                    foreach ($rowData as $col => [$val, $type]) {
                        $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val, $type);
                    }

                    foreach (['G', 'H'] as $numCol) {
                        $sheet->getStyle("{$numCol}{$rowNum}")->getNumberFormat()->setFormatCode($numFmt);
                    }

                    $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
                        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                    ]);
                    $sheet->getRowDimension($rowNum)->setRowHeight(18);
                    $rowNum++;
                }
            }
        }

        if ($rowNum > 4) {
            $sheet->getStyle("A3:{$lastCol}".($rowNum - 1))->applyFromArray([
                'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF2E7D32']]],
            ]);
        }

        $sheet->freezePane('A4');
    }

    private function buildExportInfoSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Keterangan');
        $sheet->getColumnDimension('A')->setWidth(24);
        $sheet->getColumnDimension('B')->setWidth(60);

        $sheet->mergeCells('A1:B1');
        $sheet->setCellValue('A1', 'KETERANGAN FILE EXPORT OPENING BALANCE');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);

        $infos = [
            ['Sheet 1 — Data Opening Balance', 'Berisi ringkasan data Opening Balance: klien, saldo awal, periode, status pembayaran, dan status approval.'],
            ['Sheet 2 — Rincian Invoice Asal',  'Berisi detail per Invoice Asal dari setiap Opening Balance: no. invoice, tanggal, deskripsi, dan sisa tagihan.'],
            ['Sheet 3 — Item Invoice Asal',     'Berisi item/barang per Invoice Asal: nama barang, qty, harga satuan, dan subtotal.'],
        ];

        $row = 3;
        foreach ($infos as $i => [$sheetName, $desc]) {
            $sheet->setCellValue("A{$row}", $sheetName);
            $sheet->setCellValue("B{$row}", $desc);
            $bg = $i % 2 === 0 ? 'FFFFFFFF' : 'FFF1F8E9';
            $sheet->getStyle("A{$row}:B{$row}")->applyFromArray([
                'font' => ['size' => 9],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE0E0E0']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getRowDimension($row)->setRowHeight(24);
            $row++;
        }
    }

    // ─── Private: Auth Helpers ────────────────────────────────────────────────

    private function findOpeningBalanceOrFail(int $id): Invoice
    {
        $invoice = $this->service->findOrFail($id);
        abort_if(! $invoice->is_opening_balance, 404, 'Opening balance tidak ditemukan');

        if ($invoice->klien_ar_id) {
            $this->authorizeKlienArOwnership($invoice->klien_ar_id);
        }

        return $invoice;
    }

    /**
     * Tolak akses kalau user AR murni (PIC AR) mencoba mengelola/memilih Client
     * yang bukan miliknya. Admin/Manager/Supervisor tetap punya akses global.
     */
    private function authorizeKlienArOwnership(int $klienArId): void
    {
        $picArKaryawanId = RoleHelper::picArKaryawanIdFor(auth()->user());
        if ($picArKaryawanId === null) {
            return;
        }

        $klien = KlienAr::withTrashed()->find($klienArId);

        abort_if(
            ! $klien || (int) $klien->karyawan_ar_id !== $picArKaryawanId,
            403,
            'Anda hanya dapat mengelola opening balance untuk Client yang ditugaskan kepada Anda'
        );
    }

    private function authorizeViewOpeningBalance(): void
    {
        abort_if(
            ! RoleHelper::canViewOpeningBalance(auth()->user()),
            403,
            'Tidak memiliki akses ke data opening balance'
        );
    }

    private function authorizeOperateOpeningBalance(): void
    {
        abort_if(
            ! RoleHelper::canOperateOpeningBalance(auth()->user()),
            403,
            'Tidak memiliki akses untuk mengelola opening balance'
        );
    }

    private function authorizeApproveOpeningBalance(): void
    {
        abort_if(
            ! RoleHelper::canApproveOpeningBalance(auth()->user()),
            403,
            'Tidak memiliki akses untuk menyetujui opening balance'
        );
    }
}
