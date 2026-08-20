<?php

namespace App\Domain\Finance\OpeningBalance\Controllers;

use App\Domain\Finance\Invoice\Resources\InvoiceResource;
use App\Domain\Finance\Invoice\Resources\OpeningBalanceDetailResource;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\OpeningBalance\Jobs\ProcessOpeningBalanceBulkApproveJob;
use App\Domain\Finance\OpeningBalance\Jobs\ProcessOpeningBalanceImportJob;
use App\Domain\Finance\OpeningBalance\Requests\BulkStoreOpeningBalanceRequest;
use App\Domain\Finance\OpeningBalance\Requests\StoreOpeningBalanceRequest;
use App\Domain\Finance\OpeningBalance\Services\OpeningBalanceBulkApproveCacheService;
use App\Domain\Finance\OpeningBalance\Services\OpeningBalanceImportTemplateService;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\KlienAr;
use App\Models\OpeningBalanceDetailItem;
use App\Models\OpeningBalanceImportBatch;
use App\Support\Helpers\ArFilterScope;
use App\Support\Helpers\ImportEtaCalculator;
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

    public function __construct(
        private readonly InvoiceService $service,
        private readonly OpeningBalanceBulkApproveCacheService $bulkApproveCache,
    ) {}

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
            // Perlu dinested eksplisit di sini (bukan cuma 'klienAr' di atas) supaya
            // KlienAr::resolveContactPhone()/resolveContactEmail() bisa resolve nomor
            // WA/email investor asli untuk klien RESTO — tanpa ini keduanya diam-diam
            // fallback ke null/no_wa manual karena relationLoaded('resto') gagal.
            'klienAr.resto.investor',
            'klienAr.perusahaan',
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

    public function storeBulk(BulkStoreOpeningBalanceRequest $request): JsonResponse
    {
        $this->authorizeOperateOpeningBalance();

        $items = $request->validated('items');
        foreach ($items as $idx => $item) {
            $this->authorizeKlienArOwnership((int) $item['klien_ar_id'], $idx);
        }

        $invoices = $this->service->createOpeningBalanceBulk($items);

        return $this->createdResponse(
            InvoiceResource::collection($invoices),
            count($invoices).' opening balance berhasil diajukan untuk persetujuan'
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

        if ($this->bulkApproveCache->activeBatchId(auth()->id())) {
            return $this->errorResponse('Masih ada proses Approve Semua yang berjalan untuk Anda. Tunggu sampai selesai.', 409);
        }

        $batchId = $this->bulkApproveCache->startBatch(auth()->id(), count($payload['ids']));

        ProcessOpeningBalanceBulkApproveJob::dispatch($batchId, $payload['ids'], $payload['note'] ?? null, auth()->id());

        return $this->successResponse([
            'batch_id' => $batchId,
            'status' => 'queued',
            'total' => count($payload['ids']),
        ], 'Permintaan approve semua diterima. Sedang diproses di latar belakang.', 202);
    }

    public function bulkApproveActive(): JsonResponse
    {
        $batchId = $this->bulkApproveCache->activeBatchId(auth()->id());

        return $this->successResponse($batchId ? $this->bulkApproveCache->progress($batchId) : null);
    }

    public function bulkApproveStatus(string $batch): JsonResponse
    {
        $progress = $this->bulkApproveCache->progress($batch);
        if (! $progress) {
            return $this->notFoundResponse('Batch approve semua tidak ditemukan atau sudah kedaluwarsa');
        }

        $user = auth()->user();
        if ((int) $progress['user_id'] !== $user->id && ! RoleHelper::hasAnyRole($user, ['ADMIN', 'MANAGER', 'SUPERVISOR'])) {
            return $this->unauthorizedResponse();
        }

        return $this->successResponse($progress);
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

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeOperateOpeningBalance();

        $payload = $request->validate([
            'alasan' => ['required', 'string', 'max:500'],
        ]);

        $invoice = $this->findOpeningBalanceOrFail($id);
        $this->service->deleteOpeningBalance($invoice, $payload['alasan']);

        Log::channel('security')->info('Opening balance dihapus', [
            'user_id' => auth()->id(),
            'invoice_id' => $invoice->id,
            'no_invoice' => $invoice->no_invoice,
            'alasan' => $payload['alasan'],
            'ip' => $request->ip(),
        ]);

        return $this->successResponse(null, 'Opening balance berhasil dihapus');
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

    /**
     * "needs_confirmation" TIDAK ada di enum status DB — murni transformasi response.
     * Batch fisiknya tetap 'processing' selama menunggu keputusan user (lihat
     * OpeningBalanceImportBatch::failStale() & OpeningBalanceImportService::process()),
     * supaya tidak perlu migration untuk nilai enum baru.
     */
    private function formatImportStatus(OpeningBalanceImportBatch $batch): array
    {
        // Pass 1 (resolusi grup) mengisi processed_ob; setelah pass 1 selesai
        // nilainya beku di total_ob dan pass 2 (insert) lanjut lewat
        // inserted_ob+skipped_ob+failed_ob — ambil yang terbesar supaya ETA
        // otomatis ikut pass mana pun yang sedang berjalan.
        $etaProcessed = max($batch->processed_ob, $batch->inserted_ob + $batch->skipped_ob + $batch->failed_ob);
        $eta = ImportEtaCalculator::compute($batch->created_at, $etaProcessed, $batch->total_ob);

        $awaitingConfirmation = ($batch->errors['awaiting_confirmation'] ?? false) === true;

        return [
            'batch_id' => $batch->id,
            'status' => $awaitingConfirmation ? 'needs_confirmation' : $batch->status,
            'started_at' => optional($batch->created_at)->toIso8601String(),
            'elapsed_seconds' => $eta['elapsed_seconds'],
            'estimated_remaining_seconds' => $eta['estimated_remaining_seconds'],
            'estimated_completion_at' => $eta['estimated_completion_at'],
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
            'errors' => $batch->errors['failed'] ?? [],
            'skipped_details' => $batch->errors['skipped'] ?? [],
            'conflicts' => $batch->errors['conflicts'] ?? [],
            'message' => $batch->message,
        ];
    }

    /** Reset progres lalu dispatch ulang job — dipakai confirm-replace & confirm-skip (perbedaannya hanya nilai 'resolution'). */
    private function requeueWithResolution(OpeningBalanceImportBatch $batch, string $resolution): void
    {
        $errors = $batch->errors ?? [];
        $errors['resolution'] = $resolution;
        $errors['awaiting_confirmation'] = false;

        $batch->update([
            'errors' => $errors,
            'status' => 'queued',
            'message' => null,
            'processed_ob' => 0,
            'inserted_ob' => 0,
            'skipped_ob' => 0,
            'failed_ob' => 0,
            'inserted_detail' => 0,
            'inserted_item' => 0,
        ]);

        ProcessOpeningBalanceImportJob::dispatch($batch->id);
    }

    private function findAwaitingConfirmationBatchOrFail(string $batch): OpeningBalanceImportBatch|JsonResponse
    {
        $record = OpeningBalanceImportBatch::find($batch);
        if (! $record) {
            return $this->notFoundResponse('Batch import tidak ditemukan.');
        }

        if (($record->errors['awaiting_confirmation'] ?? false) !== true) {
            return $this->errorResponse('Batch ini tidak sedang menunggu konfirmasi.', 422);
        }

        return $record;
    }

    public function importConfirmReplace(string $batch): JsonResponse
    {
        $this->authorizeOperateOpeningBalance();

        $record = $this->findAwaitingConfirmationBatchOrFail($batch);
        if ($record instanceof JsonResponse) {
            return $record;
        }

        $this->requeueWithResolution($record, 'replace');

        return $this->successResponse(
            ['batch_id' => $record->id, 'status' => 'queued'],
            'Import dilanjutkan — Opening Balance lama yang bentrok akan digantikan.'
        );
    }

    public function importConfirmSkip(string $batch): JsonResponse
    {
        $this->authorizeOperateOpeningBalance();

        $record = $this->findAwaitingConfirmationBatchOrFail($batch);
        if ($record instanceof JsonResponse) {
            return $record;
        }

        $this->requeueWithResolution($record, 'skip');

        return $this->successResponse(
            ['batch_id' => $record->id, 'status' => 'queued'],
            'Import dilanjutkan — data yang bentrok akan dilewati.'
        );
    }

    public function importCancel(string $batch): JsonResponse
    {
        $this->authorizeOperateOpeningBalance();

        $record = $this->findAwaitingConfirmationBatchOrFail($batch);
        if ($record instanceof JsonResponse) {
            return $record;
        }

        $record->cancel('Import dibatalkan oleh pengguna.');

        return $this->successResponse(null, 'Import dibatalkan.');
    }

    // ─── Export ───────────────────────────────────────────────────────────────

    /**
     * Filter export Opening Balance, sudah termasuk scoping role via ArFilterScope —
     * dipakai bersama oleh streamXlsxExport(), streamCsvExport(), dan exportRowCount()
     * supaya definisi filter tidak terduplikasi.
     */
    private function resolveExportFilters(Request $request): array
    {
        $filters = $request->only([
            'search', 'status', 'klien_ar_id', 'karyawan_id',
            'tanggal_dari', 'tanggal_sampai', 'approval_status', 'segment',
        ]);
        $filters['is_opening_balance'] = true;
        ArFilterScope::apply($filters, auth()->user());

        return $filters;
    }

    /**
     * Dispatcher format export. Default 'xlsx' (BUKAN 'csv' seperti Invoice reguler) —
     * exportDirExcel()/exportDirExcelB2B() di FE (tampilan Director) masih memanggil
     * endpoint ini tanpa query 'format', jadi default harus tetap XLSX supaya perilaku
     * lama mereka tidak berubah.
     */
    public function export(Request $request): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $this->authorizeViewOpeningBalance();

        $format = strtolower((string) $request->query('format', 'xlsx'));

        if ($format === 'csv') {
            return $this->streamCsvExport($request);
        }

        if (! class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        return $this->streamXlsxExport($request);
    }

    /**
     * Jumlah baris (level-item) yang akan dihasilkan export untuk filter yang sama —
     * dipakai FE untuk peringatan real-time XLSX vs CSV di modal Export Opening Balance.
     * Sengaja ringan: cuma WHERE ... IN + COUNT, tanpa hydrate model.
     */
    public function exportRowCount(Request $request): JsonResponse
    {
        $this->authorizeViewOpeningBalance();

        $filters = $this->resolveExportFilters($request);
        $obIds = $this->service->getExportIds($filters);

        $rowCount = OpeningBalanceDetailItem::whereHas(
            'obDetail',
            fn ($q) => $q->whereIn('invoice_id', $obIds)
        )->count();

        return $this->successResponse(['row_count' => $rowCount]);
    }

    private function streamXlsxExport(Request $request): BinaryFileResponse
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $filters = $this->resolveExportFilters($request);
        $records = $this->service->getAllForExport($filters);

        $spreadsheet = new Spreadsheet;

        // Sheet 1: Data Opening Balance (termasuk rincian invoice asal)
        $sheet1 = $spreadsheet->getActiveSheet();
        $this->buildExportObSheet($sheet1, $records);

        // Sheet 2: Item Invoice Asal
        $sheet2 = $spreadsheet->createSheet();
        $this->buildExportItemSheet($sheet2, $records);

        // Sheet 3: Keterangan
        $sheet3 = $spreadsheet->createSheet();
        $this->buildExportInfoSheet($sheet3);

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

    /**
     * Sheet "Data Opening Balance" — gabungan header OB + rincian invoice asal (1 baris =
     * 1 rincian; OB tanpa rincian tetap 1 baris dengan kolom rincian dikosongkan), meniru
     * pola flat template Import Master Opening Balance. Kode/Nama Resto (C-D) sengaja
     * ditaruh di depan dekat Klien supaya asal resto tiap baris langsung terlihat tanpa
     * scroll. Kolom OB-level (A-B, E-J) & audit (R-U) sengaja DIULANG di tiap baris rincian
     * milik OB yang sama supaya tiap baris tetap informatif berdiri sendiri (dikonfirmasi
     * user, lihat plan).
     */
    private function buildExportObSheet(Worksheet $sheet, $records): void
    {
        $sheet->setTitle('Data Opening Balance');

        $cols = [
            'A' => ['No. Opening Balance',  28],
            'B' => ['Klien',                30],
            'C' => ['Kode Resto',           16],
            'D' => ['Nama Resto',           22],
            'E' => ['Entitas Bisnis',       20],
            'F' => ['Tanggal OB',           16],
            'G' => ['Saldo Awal',           20],
            'H' => ['Total Terbayar',       20],
            'I' => ['Sisa Tagihan',         20],
            'J' => ['Keterangan OB',        30],
            'K' => ['No. Invoice Asal',     24],
            'L' => ['Tanggal Invoice Asal', 18],
            'M' => ['Jumlah Tagihan Asal',  20],
            'N' => ['Sisa Tagihan Asal',    20],
            'O' => ['Deskripsi',            30],
            'P' => ['Keterangan Rincian',   28],
            'Q' => ['Jumlah Item',          12],
            'R' => ['Status',               14],
            'S' => ['Approval',             16],
            'T' => ['Dibuat Oleh',          20],
            'U' => ['Tanggal Dibuat',       20],
        ];
        $lastCol = 'U';

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

        $rowNum = 5;
        $statusColors = ['DRAFT' => 'FF757575', 'TERKIRIM' => 'FF1565C0', 'SEBAGIAN' => 'FFE65100', 'LUNAS' => 'FF2E7D32'];
        $approvalColors = ['PENDING' => 'FFE65100', 'APPROVED' => 'FF2E7D32', 'REJECTED' => 'FFC62828'];

        foreach ($records as $inv) {
            $obValues = [
                'A' => [$inv->no_invoice,                                              DataType::TYPE_STRING],
                'B' => [$inv->klienAr?->nama_klien ?? '-',                             DataType::TYPE_STRING],
                'E' => [$inv->perusahaan?->nama_singkatan_perusahaan ?? '-',           DataType::TYPE_STRING],
                'F' => [$inv->tanggal_invoice ? Carbon::parse($inv->tanggal_invoice)->format('d-m-Y') : '-', DataType::TYPE_STRING],
                'G' => [(float) $inv->subtotal,         DataType::TYPE_NUMERIC],
                'H' => [(float) $inv->total_pembayaran, DataType::TYPE_NUMERIC],
                'I' => [(float) $inv->sisa_tagihan,     DataType::TYPE_NUMERIC],
                'J' => [$inv->keterangan ?? '-',                                       DataType::TYPE_STRING],
                'R' => [$inv->status ?? '-',                                           DataType::TYPE_STRING],
                'S' => [$inv->approval_status ?? '-',                                  DataType::TYPE_STRING],
                'T' => [$inv->createdBy?->username ?? '-',                             DataType::TYPE_STRING],
                'U' => [$inv->created_at ? Carbon::parse($inv->created_at)->format('d-m-Y H:i') : '-', DataType::TYPE_STRING],
            ];

            $details = $inv->openingBalanceDetails->isEmpty() ? [null] : $inv->openingBalanceDetails;

            foreach ($details as $detail) {
                $detailValues = $detail ? [
                    'C' => [$detail->kode_resto ?? '-',                                                               DataType::TYPE_STRING],
                    'D' => [$detail->nama_resto ?? '-',                                                               DataType::TYPE_STRING],
                    'K' => [$detail->no_invoice_asal,                                                                 DataType::TYPE_STRING],
                    'L' => [$detail->tanggal_invoice_asal ? Carbon::parse($detail->tanggal_invoice_asal)->format('d-m-Y') : '-', DataType::TYPE_STRING],
                    'M' => [(float) $detail->jumlah_tagihan_asal,                                                     DataType::TYPE_NUMERIC],
                    'N' => [(float) $detail->sisa_tagihan_asal,                                                       DataType::TYPE_NUMERIC],
                    'O' => [$detail->deskripsi,                                                                       DataType::TYPE_STRING],
                    'P' => [$detail->keterangan ?? '-',                                                               DataType::TYPE_STRING],
                    'Q' => [$detail->items->count(),                                                                  DataType::TYPE_NUMERIC],
                ] : [
                    'C' => ['-', DataType::TYPE_STRING],
                    'D' => ['-', DataType::TYPE_STRING],
                    'K' => ['-', DataType::TYPE_STRING],
                    'L' => ['-', DataType::TYPE_STRING],
                    'M' => [0.0, DataType::TYPE_NUMERIC],
                    'N' => [0.0, DataType::TYPE_NUMERIC],
                    'O' => ['-', DataType::TYPE_STRING],
                    'P' => ['-', DataType::TYPE_STRING],
                    'Q' => [0, DataType::TYPE_NUMERIC],
                ];

                foreach ($obValues + $detailValues as $col => [$val, $type]) {
                    $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val, $type);
                }

                $statusColor = $statusColors[$inv->status] ?? 'FF212121';
                $sheet->getStyle("R{$rowNum}")->getFont()->setColor(new Color($statusColor))->setBold(true);

                $approvalColor = $approvalColors[$inv->approval_status] ?? 'FF212121';
                $sheet->getStyle("S{$rowNum}")->getFont()->setColor(new Color($approvalColor))->setBold(true);

                $rowNum++;
            }
        }

        if ($rowNum > 5) {
            $sheet->getStyle("G5:I".($rowNum - 1))->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("M5:N".($rowNum - 1))->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("A4:{$lastCol}".($rowNum - 1))->applyFromArray([
                'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF2E7D32']]],
            ]);
        }

        $sheet->freezePane('A5');
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

        $rowNum = 4;

        foreach ($records as $inv) {
            foreach ($inv->openingBalanceDetails as $detail) {
                foreach ($detail->items as $item) {
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

                    $rowNum++;
                }
            }
        }

        if ($rowNum > 4) {
            $sheet->getStyle("G4:H".($rowNum - 1))->getNumberFormat()->setFormatCode('#,##0.00');
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
            ['Sheet 1 — Data Opening Balance', 'Berisi data Opening Balance beserta rincian invoice asalnya (1 baris = 1 rincian invoice; OB tanpa rincian tetap 1 baris): klien, saldo awal, periode, rincian invoice asal, status pembayaran, dan status approval.'],
            ['Sheet 2 — Item Invoice Asal',     'Berisi item/barang per Invoice Asal: nama barang, qty, harga satuan, dan subtotal.'],
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

    /**
     * Export CSV — gabungkan sheet Data OB (termasuk rincian invoice asal, sudah menyatu
     * lewat buildExportObSheet()) dengan Item Invoice Asal jadi 1 baris per item, kolom
     * berdampingan dipisah 1 kolom kosong per blok (meniru InvoiceController::streamCsvExport()).
     * Sheet "Keterangan" (legend statis) sengaja tidak ikut, karena tidak ada isi data. OB
     * tanpa detail, atau detail tanpa item, tetap ditulis minimal 1 baris (padding kolom
     * kosong) supaya tidak hilang dari CSV — mirror perilaku buildExportObSheet() yang selalu
     * menampilkan tiap OB/rincian apa pun isinya.
     */
    private function streamCsvExport(Request $request): StreamedResponse
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $filters = $this->resolveExportFilters($request);
        $records = $this->service->getAllForExport($filters);

        $headerObDetail = [
            'No. Opening Balance', 'Klien', 'Kode Resto', 'Nama Resto', 'Entitas Bisnis', 'Tanggal OB',
            'Saldo Awal', 'Total Terbayar', 'Sisa Tagihan', 'Keterangan OB',
            'No. Invoice Asal', 'Tanggal Invoice Asal', 'Jumlah Tagihan Asal', 'Sisa Tagihan Asal',
            'Deskripsi', 'Keterangan Rincian', 'Jumlah Item',
            'Status', 'Approval', 'Dibuat Oleh', 'Tanggal Dibuat',
        ];
        $headerItem = [
            'No. Opening Balance', 'No. Invoice Asal', 'Kode Barang', 'Nama Barang',
            'Qty', 'Satuan', 'Harga Satuan', 'Subtotal', 'Keterangan',
        ];
        $header = array_merge($headerObDetail, [''], $headerItem);

        $itemBlank = array_fill(0, count($headerItem), '');

        return response()->streamDownload(function () use ($records, $header, $itemBlank) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
            // Titik koma, bukan koma — locale Excel Indonesia pakai ";" sebagai list
            // separator sistem, jadi file terbuka langsung terbagi rapi per kolom.
            fputcsv($handle, $header, ';');

            foreach ($records as $inv) {
                $obPrefix = [
                    $inv->no_invoice,
                    $inv->klienAr?->nama_klien ?? '-',
                ];
                $obSuffix = [
                    $inv->perusahaan?->nama_singkatan_perusahaan ?? '-',
                    $inv->tanggal_invoice ? Carbon::parse($inv->tanggal_invoice)->format('d-m-Y') : '-',
                    (float) $inv->subtotal,
                    (float) $inv->total_pembayaran,
                    (float) $inv->sisa_tagihan,
                    $inv->keterangan ?? '-',
                ];
                $auditValues = [
                    $inv->status ?? '-',
                    $inv->approval_status ?? '-',
                    $inv->createdBy?->username ?? '-',
                    $inv->created_at ? Carbon::parse($inv->created_at)->format('d-m-Y H:i') : '-',
                ];

                if ($inv->openingBalanceDetails->isEmpty()) {
                    $obDetailRow = array_merge($obPrefix, ['-', '-'], $obSuffix, ['-', '-', 0, 0, '-', '-', 0], $auditValues);
                    fputcsv($handle, array_merge($obDetailRow, [''], $itemBlank), ';');

                    continue;
                }

                foreach ($inv->openingBalanceDetails as $detail) {
                    $restoValues = [
                        $detail->kode_resto ?? '-',
                        $detail->nama_resto ?? '-',
                    ];
                    $detailValues = [
                        $detail->no_invoice_asal,
                        $detail->tanggal_invoice_asal ? Carbon::parse($detail->tanggal_invoice_asal)->format('d-m-Y') : '-',
                        (float) $detail->jumlah_tagihan_asal,
                        (float) $detail->sisa_tagihan_asal,
                        $detail->deskripsi,
                        $detail->keterangan ?? '-',
                        $detail->items->count(),
                    ];
                    $obDetailRow = array_merge($obPrefix, $restoValues, $obSuffix, $detailValues, $auditValues);

                    if ($detail->items->isEmpty()) {
                        fputcsv($handle, array_merge($obDetailRow, [''], $itemBlank), ';');

                        continue;
                    }

                    foreach ($detail->items as $item) {
                        $itemRow = [
                            $inv->no_invoice,
                            $detail->no_invoice_asal,
                            $item->kode_barang ?? $item->barang?->kode_barang ?? '-',
                            $item->nama_barang,
                            (float) $item->qty,
                            $item->satuan ?? '-',
                            (float) $item->harga_satuan,
                            (float) $item->subtotal,
                            $item->keterangan ?? '-',
                        ];

                        fputcsv($handle, array_merge($obDetailRow, [''], $itemRow), ';');
                    }
                }
            }

            fclose($handle);
        }, 'Data Opening Balance-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // ─── Private: Auth Helpers ────────────────────────────────────────────────

    private function findOpeningBalanceOrFail(int $id): Invoice
    {
        return $this->service->resolveOpeningBalanceForActor($id);
    }

    /**
     * Tolak akses kalau user AR murni (PIC AR) mencoba mengelola/memilih Client
     * yang bukan miliknya. Admin/Manager/Supervisor tetap punya akses global.
     */
    private function authorizeKlienArOwnership(int $klienArId, ?int $index = null): void
    {
        $picArKaryawanId = RoleHelper::picArKaryawanIdFor(auth()->user());
        if ($picArKaryawanId === null) {
            return;
        }

        $klien = KlienAr::withTrashed()->find($klienArId);
        $suffix = $index !== null ? ' (baris ke-'.($index + 1).')' : '';

        abort_if(
            ! $klien || (int) $klien->karyawan_ar_id !== $picArKaryawanId,
            403,
            'Anda hanya dapat mengelola opening balance untuk Client yang ditugaskan kepada Anda'.$suffix
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
