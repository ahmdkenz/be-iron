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
use App\Models\KlienAr;
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
        $user    = auth()->user()->load('karyawan');
        $filters = $request->only([
            'search', 'status', 'klien_ar_id', 'karyawan_id',
            'periode_bulan', 'periode_tahun',
        ]);
        $filters['is_opening_balance'] = false;

        // Scope: non-admin hanya bisa lihat invoice PT-nya sendiri
        if ($user->karyawan && !RoleHelper::hasGlobalFinanceAccess($user)) {
            $filters['perusahaan_id'] = $user->karyawan->perusahaan_id;
        }

        $list = $this->service->paginate($filters);
        return $this->paginatedResponse(
            $list->through(fn($inv) => new InvoiceResource($inv))
        );
    }

    public function summary(Request $request): JsonResponse
    {
        $user    = auth()->user()->load('karyawan');
        $filters = $request->only([
            'search', 'status', 'klien_ar_id', 'karyawan_id',
            'periode_bulan', 'periode_tahun',
        ]);
        $filters['is_opening_balance'] = false;

        if ($user->karyawan && !RoleHelper::hasGlobalFinanceAccess($user)) {
            $filters['perusahaan_id'] = $user->karyawan->perusahaan_id;
        }

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

    public function rekapKlien(Request $request): JsonResponse
    {
        $user    = auth()->user()->load('karyawan');
        $filters = $request->only(['klien_ar_id', 'periode_bulan', 'periode_tahun']);

        if ($user->karyawan && !RoleHelper::hasGlobalFinanceAccess($user)) {
            $filters['perusahaan_id'] = $user->karyawan->perusahaan_id;
        }

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

    public function export(Request $request): StreamedResponse
    {
        $user    = auth()->user()->load('karyawan');
        $filters = $request->only([
            'search', 'status', 'klien_ar_id', 'karyawan_id',
            'periode_bulan', 'periode_tahun',
        ]);
        $filters['is_opening_balance'] = false;

        if ($user->karyawan && !RoleHelper::hasGlobalFinanceAccess($user)) {
            $filters['perusahaan_id'] = $user->karyawan->perusahaan_id;
        }

        $invoices = $this->service->paginate(array_merge($filters, ['per_page' => 9999]))->items();

        $headers = [
            'No Invoice', 'Klien', 'Perusahaan', 'Tanggal Invoice',
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
                    $inv->perusahaan?->nama_singkatan_perusahaan,
                    $inv->tanggal_invoice?->format('Y-m-d'),
                    $inv->periode_awal?->format('Y-m-d'),
                    $inv->periode_akhir?->format('Y-m-d'),
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

        $user    = auth()->user()->load('karyawan');
        $filters = $request->only([
            'search', 'status', 'klien_ar_id', 'karyawan_id',
            'periode_bulan', 'periode_tahun',
        ]);
        $filters['is_opening_balance'] = false;

        if ($user->karyawan && !RoleHelper::hasGlobalFinanceAccess($user)) {
            $filters['perusahaan_id'] = $user->karyawan->perusahaan_id;
        }

        $invoices = $this->service->getAllForExport($filters);

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Invoice AR');

        $cols = [
            'A' => ['No Invoice',             24],
            'B' => ['Klien',                  32],
            'C' => ['Perusahaan',             18],
            'D' => ['Tanggal Invoice',        18],
            'E' => ['Periode Awal',           16],
            'F' => ['Periode Akhir',          16],
            'G' => ['Subtotal',               18],
            'H' => ['Tagihan Sebelumnya',     22],
            'I' => ['Total Tagihan',          18],
            'J' => ['Total Pembayaran',       20],
            'K' => ['Sisa Tagihan',           18],
            'L' => ['Status',                 14],
        ];
        $lastCol = 'L';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'DATA INVOICE AR');
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

            $rowData = [
                'A' => [$inv->no_invoice,                                   DataType::TYPE_STRING],
                'B' => [$inv->klienAr?->nama_klien ?? '-',                   DataType::TYPE_STRING],
                'C' => [$inv->perusahaan?->nama_singkatan_perusahaan ?? '-', DataType::TYPE_STRING],
                'D' => [$inv->tanggal_invoice?->format('Y-m-d') ?? '-',     DataType::TYPE_STRING],
                'E' => [$inv->periode_awal?->format('Y-m-d') ?? '-',        DataType::TYPE_STRING],
                'F' => [$inv->periode_akhir?->format('Y-m-d') ?? '-',       DataType::TYPE_STRING],
                'G' => [(float) $inv->subtotal,                              DataType::TYPE_NUMERIC],
                'H' => [(float) $inv->tagihan_periode_sebelumnya,            DataType::TYPE_NUMERIC],
                'I' => [(float) $inv->total_tagihan,                         DataType::TYPE_NUMERIC],
                'J' => [(float) $inv->total_pembayaran,                      DataType::TYPE_NUMERIC],
                'K' => [(float) $inv->sisa_tagihan,                          DataType::TYPE_NUMERIC],
                'L' => [$inv->status,                                        DataType::TYPE_STRING],
            ];

            foreach ($rowData as $col => [$val, $type]) {
                $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val, $type);
            }

            foreach (['G', 'H', 'I', 'J', 'K'] as $c) {
                $sheet->getStyle("{$c}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
            }

            $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);

            $statusColor = $statusColors[$inv->status] ?? 'FF000000';
            $sheet->getStyle("L{$rowNum}")->getFont()
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

        $temp = tempnam(sys_get_temp_dir(), 'export_invoice_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'invoice-ar-' . now()->format('Ymd-His') . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    public function importTemplate(): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $spreadsheet = new Spreadsheet();

        $this->buildInvoiceDataSheet($spreadsheet->getActiveSheet());
        $this->buildInvoiceItemSheet($spreadsheet->createSheet());
        $this->buildInvoiceInstructionSheet($spreadsheet->createSheet());

        $spreadsheet->setActiveSheetIndex(0);

        $temp = tempnam(sys_get_temp_dir(), 'tpl_invoice_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'template-invoice-ar.xlsx', [
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
        ]);

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
            $rows1       = $this->invoiceParseXlsxSheet($spreadsheet->getSheet(0), 'no_urut');
            $rows2       = $spreadsheet->getSheetCount() > 1
                ? $this->invoiceParseXlsxSheet($spreadsheet->getSheet(1), 'no_urut_invoice')
                : [];
        }

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
            $noUrut       = $this->invoiceImportStr($row[0] ?? '');
            $noInvoice    = $this->invoiceImportStr($row[1] ?? '');
            $namaKlien    = $this->invoiceImportStr($row[2] ?? '');
            $tanggal      = $this->invoiceImportDate($row[3] ?? '');
            $periodeAwal  = $this->invoiceImportDate($row[4] ?? '');
            $periodeAkhir = $this->invoiceImportDate($row[5] ?? '');
            $noSuratJalan = $this->invoiceImportStr($row[6] ?? '');
            $tagihanSblm  = $this->invoiceImportNum($row[7] ?? '');
            $keterangan   = $this->invoiceImportStr($row[8] ?? '');

            $validated = Validator::make(
                [
                    'no_urut'         => $noUrut,
                    'no_invoice'      => $noInvoice,
                    'nama_klien'      => $namaKlien,
                    'tanggal_invoice' => $tanggal,
                    'periode_awal'    => $periodeAwal,
                    'periode_akhir'   => $periodeAkhir,
                ],
                [
                    'no_urut'         => ['required'],
                    'no_invoice'      => ['required', 'string', 'unique:tb_invoice,no_invoice'],
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

            $klien = KlienAr::where('nama_klien', $namaKlien)->first();
            if (!$klien) {
                $errors[] = ['sheet' => 'Invoice', 'row' => $lineNumber, 'message' => "Klien '{$namaKlien}' tidak ditemukan di sistem"];
                continue;
            }

            try {
                $invoice = Invoice::create([
                    'no_invoice'                 => $noInvoice,
                    'tanggal_invoice'            => $tanggal,
                    'periode_awal'               => $periodeAwal,
                    'periode_akhir'              => $periodeAkhir,
                    'klien_ar_id'                => $klien->id,
                    'perusahaan_id'              => $klien->perusahaan_id,
                    'karyawan_id'                => $user->karyawan->id,
                    'no_surat_jalan'             => $noSuratJalan,
                    'subtotal'                   => 0,
                    'tagihan_periode_sebelumnya' => $tagihanSblm,
                    'total_tagihan'              => $tagihanSblm,
                    'total_pembayaran'           => 0,
                    'sisa_tagihan'               => $tagihanSblm,
                    'status'                     => 'DRAFT',
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

        // Recalculate totals for invoices that received items
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

        // Upload semua invoice yang berhasil dibuat ke Google Drive (setelah items selesai)
        foreach ($invoiceMapping as $invoice) {
            UploadInvoiceToGDriveJob::dispatch($invoice->id);
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
            $bulanAwal = $invoice->tanggal_invoice->copy()->startOfMonth();
            $bulanAkhir = $invoice->tanggal_invoice->copy()->endOfMonth();
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
            $bulanAwal = $invoice->tanggal_invoice->copy()->startOfMonth();
            $bulanAkhir = $invoice->tanggal_invoice->copy()->endOfMonth();
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

        if ($request->has('html')) {
            return view('finance.invoice-print', compact('invoice', 'signatureData', 'regularInvoicesInPeriod'))->render();
        }

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

    private function buildInvoiceDataSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Invoice');
        $cols = [
            'A' => ['no_urut',                    12],
            'B' => ['no_invoice',                  26],
            'C' => ['nama_klien',                  32],
            'D' => ['tanggal_invoice',             20],
            'E' => ['periode_awal',                20],
            'F' => ['periode_akhir',               20],
            'G' => ['no_surat_jalan',              22],
            'H' => ['tagihan_periode_sebelumnya',  26],
            'I' => ['keterangan',                  32],
        ];
        $lastCol = 'I';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'TEMPLATE IMPORT INVOICE AR — SHEET 1: DATA INVOICE');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0D47A1']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', 'Isi data invoice. Gunakan no_urut yang sama di Sheet "Item Invoice" untuk menghubungkan item. Lihat sheet "Petunjuk Pengisian" untuk panduan.');
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
            'B' => 'SI-B2C-' . date('dmY') . '-001',
            'C' => 'Budi Santoso',
            'D' => date('Y-m-d'),
            'E' => date('Y-m-01'),
            'F' => date('Y-m-t'),
            'G' => 'SJ-001',
            'H' => '0',
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
            $sheet->getStyle("H{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            foreach (['D', 'E', 'F'] as $dateCol) {
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

    private function buildInvoiceInstructionSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Petunjuk Pengisian');
        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(52);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(40);

        $row = 1;

        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", 'PETUNJUK PENGISIAN — TEMPLATE IMPORT INVOICE AR');
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
            '7. Format tanggal: YYYY-MM-DD (contoh: 2025-06-01).',
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
            ['  KETERANGAN KOLOM — SHEET "INVOICE"',      'FF1565C0', $this->getInvoiceColInfos()],
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

    private function getInvoiceColInfos(): array
    {
        return [
            ['no_urut',                    'Nomor urut baris (penghubung ke Sheet Item Invoice)',     'Ya',       'Angka unik per baris. Contoh: 1, 2, 3'],
            ['no_invoice',                 'Nomor invoice unik',                                      'Ya',       'Harus unik di sistem. Contoh: SI-B2C-21052026-001'],
            ['nama_klien',                 'Nama klien AR sesuai data di sistem',                     'Ya',       'Harus persis sesuai nama klien di sistem'],
            ['tanggal_invoice',            'Tanggal pembuatan invoice',                               'Ya',       'Format YYYY-MM-DD. Contoh: 2025-06-15'],
            ['periode_awal',               'Tanggal awal periode tagihan',                            'Ya',       'Format YYYY-MM-DD. Contoh: 2025-06-01'],
            ['periode_akhir',              'Tanggal akhir periode tagihan',                           'Ya',       'Format YYYY-MM-DD. Contoh: 2025-06-30'],
            ['no_surat_jalan',             'Nomor surat jalan',                                       'Opsional', 'Teks bebas. Contoh: SJ-001/VI/2025'],
            ['tagihan_periode_sebelumnya', 'Saldo tagihan dari periode sebelumnya',                   'Opsional', 'Angka, default: 0. Contoh: 150000'],
            ['keterangan',                 'Catatan tambahan untuk invoice',                          'Opsional', 'Teks bebas'],
        ];
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
