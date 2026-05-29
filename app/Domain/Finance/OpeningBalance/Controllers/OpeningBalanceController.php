<?php

namespace App\Domain\Finance\OpeningBalance\Controllers;

use App\Domain\Finance\Invoice\Resources\InvoiceResource;
use App\Domain\Finance\Invoice\Resources\OpeningBalanceDetailResource;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\OpeningBalance\Requests\StoreOpeningBalanceRequest;
use App\Http\Controllers\Controller;
use App\Models\Barang;
use App\Models\Invoice;
use App\Models\KlienAr;
use App\Models\OpeningBalanceDetail;
use App\Models\OpeningBalanceDetailItem;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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

class OpeningBalanceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly InvoiceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeViewOpeningBalance();

        $user    = auth()->user()->load('karyawan');
        $filters = $request->only([
            'search', 'status', 'klien_ar_id', 'karyawan_id',
            'periode_bulan', 'periode_tahun', 'approval_status',
        ]);
        $filters['is_opening_balance'] = true;

        if ($user->karyawan && !RoleHelper::hasGlobalFinanceAccess($user)) {
            $filters['perusahaan_id'] = $user->karyawan->perusahaan_id;
        }

        $list = $this->service->paginate($filters);

        return $this->paginatedResponse(
            $list->through(fn($invoice) => new InvoiceResource($invoice))
        );
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorizeViewOpeningBalance();

        $user    = auth()->user()->load('karyawan');
        $filters = $request->only([
            'search', 'status', 'klien_ar_id', 'karyawan_id',
            'periode_bulan', 'periode_tahun', 'approval_status',
        ]);
        $filters['is_opening_balance'] = true;

        if ($user->karyawan && !RoleHelper::hasGlobalFinanceAccess($user)) {
            $filters['perusahaan_id'] = $user->karyawan->perusahaan_id;
        }

        return $this->successResponse($this->service->getSummary($filters));
    }

    public function store(StoreOpeningBalanceRequest $request): JsonResponse
    {
        $this->authorizeOperateOpeningBalance();

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

        return $this->successResponse(
            new InvoiceResource($updated),
            'Opening balance berhasil disetujui'
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
                $invoice->openingBalanceDetails()->orderBy('tanggal_invoice_asal')->get()
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

    // ─── Export ───────────────────────────────────────────────────────────────

    public function export(Request $request): BinaryFileResponse|JsonResponse
    {
        $this->authorizeViewOpeningBalance();

        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $user    = auth()->user()->load('karyawan');
        $filters = $request->only([
            'search', 'status', 'klien_ar_id', 'karyawan_id',
            'periode_bulan', 'periode_tahun', 'approval_status',
        ]);
        $filters['is_opening_balance'] = true;

        if ($user->karyawan && !RoleHelper::hasGlobalFinanceAccess($user)) {
            $filters['perusahaan_id'] = $user->karyawan->perusahaan_id;
        }

        $records = $this->service->getAllForExport($filters);

        $spreadsheet = new Spreadsheet();

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

        $temp = tempnam(sys_get_temp_dir(), 'export_ob_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'Data Opening Balance-' . now()->format('Ymd-His') . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    // ─── Import Template ──────────────────────────────────────────────────────

    public function importTemplate(): BinaryFileResponse|JsonResponse
    {
        $this->authorizeViewOpeningBalance();

        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $spreadsheet = new Spreadsheet();

        $this->buildObDataSheet($spreadsheet->getActiveSheet());
        $this->buildObDetailSheet($spreadsheet->createSheet());
        $this->buildObItemSheet($spreadsheet->createSheet());
        $this->buildObInstructionSheet($spreadsheet->createSheet());

        $spreadsheet->setActiveSheetIndex(0);

        $temp = tempnam(sys_get_temp_dir(), 'tpl_ob_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'Template OB (Saldo Awal).xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    // ─── Import ───────────────────────────────────────────────────────────────

    public function import(Request $request): JsonResponse
    {
        $this->authorizeOperateOpeningBalance();

        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:2048'],
        ]);

        $user = auth()->user()->loadMissing('karyawan');
        abort_if(!$user?->karyawan?->id, 422, 'User tidak terhubung dengan data karyawan');

        $file      = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $isCsv     = in_array($extension, ['csv', 'txt']);

        $insertedOb     = 0;
        $insertedDetail = 0;
        $insertedItem   = 0;
        $totalOb        = 0;
        $totalDetail    = 0;
        $totalItem      = 0;
        $errors         = [];

        // Map: no_urut (string) → Invoice model
        $obMap = [];
        // Map: "no_urut_ob|no_invoice_asal" → detail_id
        $detailMap = [];

        // ── Pass 1: Opening Balance utama ────────────────────────────────────
        $sheet1Rows = $isCsv
            ? $this->parseObCsv($file->getRealPath())
            : $this->parseObSheetRows($file->getRealPath(), 0, 'no_invoice', 8);

        $lineNumber    = 0;
        $headerSkipped = false;

        foreach ($sheet1Rows as $row) {
            $lineNumber++;
            $firstCell = trim((string) ($row[0] ?? ''));

            if (str_starts_with($firstCell, '#')) continue;
            if (!$headerSkipped) { $headerSkipped = true; continue; }
            if (str_starts_with($firstCell, '[CONTOH]')) continue;
            if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) continue;

            $totalOb++;

            $noInvoice = $firstCell;
            $namaKlien = $this->importObValue($row[1] ?? '');
            $klien     = KlienAr::where('nama_klien', $namaKlien)->latest()->first();
            if (!$klien) {
                $errors[] = ['sheet' => 'Sheet 1', 'row' => $lineNumber, 'message' => "Klien '{$namaKlien}' tidak ditemukan di sistem."];
                continue;
            }

            $tanggal     = $this->parseObDate($row[2] ?? '');
            $periodeAwal = $this->parseObDate($row[3] ?? '');
            $periodeAkhir= $this->parseObDate($row[4] ?? '');

            if (!$tanggal || !$periodeAwal || !$periodeAkhir) {
                $errors[] = ['sheet' => 'Sheet 1', 'row' => $lineNumber, 'message' => 'Format tanggal tidak valid. Gunakan format YYYY-MM-DD atau DD-MM-YYYY.'];
                continue;
            }

            $saldoAwal  = (float) str_replace(['.', ','], ['', '.'], trim((string) ($row[5] ?? '')));
            $keterangan = $this->importObValue($row[6] ?? '');
            $noUrut     = trim((string) ($row[7] ?? ''));

            $data = [
                'no_invoice'   => $noInvoice,
                'klien_ar_id'  => $klien->id,
                'tanggal'      => $tanggal,
                'periode_awal' => $periodeAwal,
                'periode_akhir'=> $periodeAkhir,
                'saldo_awal'   => $saldoAwal,
                'keterangan'   => $keterangan,
            ];

            $validator = Validator::make($data, [
                'no_invoice'   => ['required', 'string', 'unique:tb_invoice,no_invoice'],
                'klien_ar_id'  => ['required', 'integer'],
                'tanggal'      => ['required', 'date'],
                'periode_awal' => ['required', 'date'],
                'periode_akhir'=> ['required', 'date', 'after_or_equal:periode_awal'],
                'saldo_awal'   => ['required', 'numeric', 'min:0.01'],
                'keterangan'   => ['nullable', 'string', 'max:500'],
            ]);

            if ($validator->fails()) {
                $errors[] = ['sheet' => 'Sheet 1', 'row' => $lineNumber, 'message' => implode('; ', $validator->errors()->all())];
                continue;
            }

            $exists = Invoice::where('klien_ar_id', $klien->id)
                ->where('is_opening_balance', true)
                ->whereDate('periode_awal', $periodeAwal)
                ->whereDate('periode_akhir', $periodeAkhir)
                ->exists();

            if ($exists) {
                $errors[] = ['sheet' => 'Sheet 1', 'row' => $lineNumber, 'message' => "Opening balance untuk klien '{$namaKlien}' periode {$periodeAwal} s/d {$periodeAkhir} sudah ada."];
                continue;
            }

            try {
                $invoice = $this->service->createOpeningBalance($data);
                $insertedOb++;
                if ($noUrut !== '') {
                    $obMap[$noUrut] = $invoice;
                }
            } catch (\Throwable $e) {
                $errors[] = ['sheet' => 'Sheet 1', 'row' => $lineNumber, 'message' => 'Gagal membuat data: ' . $e->getMessage()];
            }
        }

        // ── Pass 2: Rincian Invoice Asal (Sheet 2, XLSX only) ────────────────
        if (!$isCsv) {
            $sheet2Rows    = $this->parseObSheetRows($file->getRealPath(), 1, 'no_urut_ob', 7);
            $lineNumber    = 0;
            $headerSkipped = false;

            foreach ($sheet2Rows as $row) {
                $lineNumber++;
                $firstCell = trim((string) ($row[0] ?? ''));

                if (str_starts_with($firstCell, '#')) continue;
                if (!$headerSkipped) { $headerSkipped = true; continue; }
                if (str_starts_with($firstCell, '[CONTOH]')) continue;
                if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) continue;

                $totalDetail++;

                $noUrutOb = $firstCell;
                if (!isset($obMap[$noUrutOb])) {
                    $errors[] = ['sheet' => 'Sheet 2', 'row' => $lineNumber, 'message' => "no_urut_ob '{$noUrutOb}' tidak ditemukan atau Opening Balance-nya gagal dibuat."];
                    continue;
                }

                $invoice         = $obMap[$noUrutOb];
                $noInvoiceAsal   = trim((string) ($row[1] ?? ''));
                $tglInvoiceAsal  = $this->parseObDate($row[2] ?? '');
                $deskripsi       = trim((string) ($row[3] ?? ''));
                $jumlahTagihan   = (float) str_replace(['.', ','], ['', '.'], trim((string) ($row[4] ?? '')));
                $sisaTagihan     = (float) str_replace(['.', ','], ['', '.'], trim((string) ($row[5] ?? '')));
                $keterangan      = $this->importObValue($row[6] ?? '');

                if (!$noInvoiceAsal || !$tglInvoiceAsal || !$deskripsi) {
                    $errors[] = ['sheet' => 'Sheet 2', 'row' => $lineNumber, 'message' => 'Kolom no_invoice_asal, tanggal_invoice_asal, dan deskripsi wajib diisi.'];
                    continue;
                }

                if ($sisaTagihan <= 0) {
                    $errors[] = ['sheet' => 'Sheet 2', 'row' => $lineNumber, 'message' => 'Sisa tagihan asal harus lebih dari 0.'];
                    continue;
                }

                try {
                    $detail = OpeningBalanceDetail::create([
                        'invoice_id'           => $invoice->id,
                        'no_invoice_asal'      => $noInvoiceAsal,
                        'tanggal_invoice_asal' => $tglInvoiceAsal,
                        'deskripsi'            => $deskripsi,
                        'jumlah_tagihan_asal'  => $jumlahTagihan > 0 ? $jumlahTagihan : $sisaTagihan,
                        'sisa_tagihan_asal'    => $sisaTagihan,
                        'keterangan'           => $keterangan,
                        'created_by'           => auth()->id(),
                    ]);

                    $insertedDetail++;
                    $mapKey = "{$noUrutOb}|{$noInvoiceAsal}";
                    $detailMap[$mapKey] = $detail->id;
                } catch (\Throwable $e) {
                    $errors[] = ['sheet' => 'Sheet 2', 'row' => $lineNumber, 'message' => 'Gagal membuat detail: ' . $e->getMessage()];
                }
            }
        }

        // ── Reconcile: update OB subtotal from sum of detail sisa values ────
        if (!$isCsv) {
            foreach ($obMap as $obInvoice) {
                $sumSisa = OpeningBalanceDetail::where('invoice_id', $obInvoice->id)
                    ->sum('sisa_tagihan_asal');
                if ($sumSisa > 0.01 && abs($sumSisa - (float) $obInvoice->subtotal) > 0.01) {
                    $obInvoice->update([
                        'subtotal'      => $sumSisa,
                        'total_tagihan' => $sumSisa,
                        'sisa_tagihan'  => max(0, $sumSisa - (float) $obInvoice->total_pembayaran),
                    ]);
                }
            }
        }

        // ── Pass 3: Item Invoice Asal (Sheet 3, XLSX only) ───────────────────
        if (!$isCsv) {
            $sheet3Rows    = $this->parseObSheetRows($file->getRealPath(), 2, 'no_urut_ob', 9);
            $lineNumber    = 0;
            $headerSkipped = false;

            // Track detail IDs that have items (to update jumlah_tagihan_asal later)
            $detailsWithItems = [];

            foreach ($sheet3Rows as $row) {
                $lineNumber++;
                $firstCell = trim((string) ($row[0] ?? ''));

                if (str_starts_with($firstCell, '#')) continue;
                if (!$headerSkipped) { $headerSkipped = true; continue; }
                if (str_starts_with($firstCell, '[CONTOH]')) continue;
                if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) continue;

                $totalItem++;

                $noUrutOb      = $firstCell;
                $noInvoiceAsal = trim((string) ($row[1] ?? ''));
                $mapKey        = "{$noUrutOb}|{$noInvoiceAsal}";

                if (!isset($detailMap[$mapKey])) {
                    $errors[] = ['sheet' => 'Sheet 3', 'row' => $lineNumber, 'message' => "Referensi no_urut_ob '{$noUrutOb}' + no_invoice_asal '{$noInvoiceAsal}' tidak ditemukan di Sheet 2."];
                    continue;
                }

                $detailId    = $detailMap[$mapKey];
                $kodeBarang  = $this->importObValue($row[2] ?? '');
                $namaBarang  = trim((string) ($row[3] ?? ''));
                $qty         = (float) str_replace(',', '.', trim((string) ($row[4] ?? '')));
                $satuan      = $this->importObValue($row[5] ?? '') ?? 'pcs';
                $hargaSatuan = (float) str_replace(['.', ','], ['', '.'], trim((string) ($row[6] ?? '')));
                $subtotal    = round($qty * $hargaSatuan, 2);
                $keterangan  = $this->importObValue($row[8] ?? '');

                if (!$namaBarang) {
                    $errors[] = ['sheet' => 'Sheet 3', 'row' => $lineNumber, 'message' => 'Kolom nama_barang wajib diisi.'];
                    continue;
                }

                if ($qty <= 0) {
                    $errors[] = ['sheet' => 'Sheet 3', 'row' => $lineNumber, 'message' => 'Qty harus lebih dari 0.'];
                    continue;
                }

                $barangId = null;
                if ($kodeBarang) {
                    $barangId = Barang::whereRaw('LOWER(kode_barang) = ?', [strtolower($kodeBarang)])->value('id');
                }
                if (!$barangId) {
                    $barangId = Barang::whereRaw('LOWER(nama_barang) = ?', [strtolower($namaBarang)])->value('id');
                }

                try {
                    OpeningBalanceDetailItem::create([
                        'ob_detail_id' => $detailId,
                        'barang_id'    => $barangId,
                        'nama_barang'  => $namaBarang,
                        'qty'          => $qty,
                        'satuan'       => $satuan,
                        'harga_satuan' => $hargaSatuan,
                        'subtotal'     => $subtotal,
                        'keterangan'   => $keterangan,
                    ]);

                    $insertedItem++;
                    $detailsWithItems[$detailId] = true;
                } catch (\Throwable $e) {
                    $errors[] = ['sheet' => 'Sheet 3', 'row' => $lineNumber, 'message' => 'Gagal membuat item: ' . $e->getMessage()];
                }
            }

            // Update jumlah_tagihan_asal for details that have items (sum of subtotals)
            foreach (array_keys($detailsWithItems) as $detailId) {
                $sumSubtotal = OpeningBalanceDetailItem::where('ob_detail_id', $detailId)->sum('subtotal');
                OpeningBalanceDetail::where('id', $detailId)->update(['jumlah_tagihan_asal' => $sumSubtotal]);
            }
        }

        $failedOb     = $totalOb - $insertedOb;
        $failedDetail = $totalDetail - $insertedDetail;
        $failedItem   = $totalItem - $insertedItem;

        $message = "Import selesai. {$insertedOb} OB ditambahkan";
        if (!$isCsv) {
            $message .= ", {$insertedDetail} detail, {$insertedItem} item";
        }
        $totalFailed = $failedOb + $failedDetail + $failedItem;
        if ($totalFailed > 0) {
            $message .= ", {$totalFailed} baris gagal";
        }
        $message .= '.';

        return $this->successResponse([
            'total_ob'       => $totalOb,
            'inserted_ob'    => $insertedOb,
            'failed_ob'      => $failedOb,
            'total_detail'   => $totalDetail,
            'inserted_detail'=> $insertedDetail,
            'failed_detail'  => $failedDetail,
            'total_item'     => $totalItem,
            'inserted_item'  => $insertedItem,
            'failed_item'    => $failedItem,
            'is_csv'         => $isCsv,
            'errors'         => $errors,
        ], $message);
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
            'F' => ['Periode Awal',         16],
            'G' => ['Periode Akhir',        16],
            'H' => ['Saldo Awal',           20],
            'I' => ['Total Terbayar',       20],
            'J' => ['Sisa Tagihan',         20],
            'K' => ['Keterangan',           32],
            'L' => ['Status',              14],
            'M' => ['Approval',             16],
            'N' => ['Dibuat Oleh',          20],
            'O' => ['Tanggal Dibuat',       20],
        ];
        $lastCol = 'O';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'DATA OPENING BALANCE');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', 'Diekspor pada: ' . now()->format('d-m-Y H:i') . '   |   Total data: ' . $records->count() . ' opening balance');
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF33691E']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF1F8E9']],
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
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E7D32']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1B5E20']]],
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
                'E' => [$inv->tanggal_invoice ? \Carbon\Carbon::parse($inv->tanggal_invoice)->format('d-m-Y') : '-', DataType::TYPE_STRING],
                'F' => [$inv->periode_awal    ? \Carbon\Carbon::parse($inv->periode_awal)->format('d-m-Y')    : '-', DataType::TYPE_STRING],
                'G' => [$inv->periode_akhir   ? \Carbon\Carbon::parse($inv->periode_akhir)->format('d-m-Y')   : '-', DataType::TYPE_STRING],
                'H' => [(float) $inv->subtotal,         DataType::TYPE_NUMERIC],
                'I' => [(float) $inv->total_pembayaran, DataType::TYPE_NUMERIC],
                'J' => [(float) $inv->sisa_tagihan,     DataType::TYPE_NUMERIC],
                'K' => [$inv->keterangan ?? '-',                                       DataType::TYPE_STRING],
                'L' => [$inv->status ?? '-',                                           DataType::TYPE_STRING],
                'M' => [$inv->approval_status ?? '-',                                  DataType::TYPE_STRING],
                'N' => [$inv->createdBy?->username ?? '-',                             DataType::TYPE_STRING],
                'O' => [$inv->created_at ? \Carbon\Carbon::parse($inv->created_at)->format('d-m-Y H:i') : '-', DataType::TYPE_STRING],
            ];

            foreach ($rowData as $col => [$val, $type]) {
                $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val, $type);
            }

            foreach (['H', 'I', 'J'] as $numCol) {
                $sheet->getStyle("{$numCol}{$rowNum}")->getNumberFormat()->setFormatCode($numFmt);
            }

            $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);

            // Status color
            $statusColors = ['DRAFT' => 'FF757575', 'TERKIRIM' => 'FF1565C0', 'SEBAGIAN' => 'FFE65100', 'LUNAS' => 'FF2E7D32'];
            $statusColor  = $statusColors[$inv->status] ?? 'FF212121';
            $sheet->getStyle("L{$rowNum}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($statusColor))->setBold(true);

            // Approval color
            $approvalColors = ['PENDING' => 'FFE65100', 'APPROVED' => 'FF2E7D32', 'REJECTED' => 'FFC62828'];
            $approvalColor  = $approvalColors[$inv->approval_status] ?? 'FF212121';
            $sheet->getStyle("M{$rowNum}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($approvalColor))->setBold(true);

            $sheet->getRowDimension($rowNum)->setRowHeight(18);
            $rowNum++;
        }

        if ($rowNum > 5) {
            $sheet->getStyle("A4:{$lastCol}" . ($rowNum - 1))->applyFromArray([
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
            'H' => ['Jumlah Item',          14],
        ];
        $lastCol = 'H';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'RINCIAN INVOICE ASAL');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getRowDimension(2)->setRowHeight(6);

        foreach ($cols as $col => [$label, $width]) {
            $sheet->setCellValue("{$col}3", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E7D32']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1B5E20']]],
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
                    'C' => [$detail->tanggal_invoice_asal ? \Carbon\Carbon::parse($detail->tanggal_invoice_asal)->format('d-m-Y') : '-', DataType::TYPE_STRING],
                    'D' => [$detail->deskripsi,                                                                       DataType::TYPE_STRING],
                    'E' => [(float) $detail->jumlah_tagihan_asal,                                                     DataType::TYPE_NUMERIC],
                    'F' => [(float) $detail->sisa_tagihan_asal,                                                       DataType::TYPE_NUMERIC],
                    'G' => [$detail->keterangan ?? '-',                                                               DataType::TYPE_STRING],
                    'H' => [$detail->items->count(),                                                                  DataType::TYPE_NUMERIC],
                ];

                foreach ($rowData as $col => [$val, $type]) {
                    $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val, $type);
                }

                foreach (['E', 'F'] as $numCol) {
                    $sheet->getStyle("{$numCol}{$rowNum}")->getNumberFormat()->setFormatCode($numFmt);
                }

                $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension($rowNum)->setRowHeight(18);
                $rowNum++;
            }
        }

        if ($rowNum > 4) {
            $sheet->getStyle("A3:{$lastCol}" . ($rowNum - 1))->applyFromArray([
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
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getRowDimension(2)->setRowHeight(6);

        foreach ($cols as $col => [$label, $width]) {
            $sheet->setCellValue("{$col}3", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E7D32']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1B5E20']]],
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
                        'C' => [$item->barang?->kode_barang ?? '-',      DataType::TYPE_STRING],
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
                        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
                        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                    ]);
                    $sheet->getRowDimension($rowNum)->setRowHeight(18);
                    $rowNum++;
                }
            }
        }

        if ($rowNum > 4) {
            $sheet->getStyle("A3:{$lastCol}" . ($rowNum - 1))->applyFromArray([
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
            'font'      => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B5E20']],
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
                'font'      => ['size' => 9],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE0E0E0']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getRowDimension($row)->setRowHeight(24);
            $row++;
        }
    }

    // ─── Private: Template Sheet Builders ────────────────────────────────────

    private function buildObDataSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Data Opening Balance');

        $cols = [
            'A' => ['no_invoice',   26],
            'B' => ['nama_klien',   32],
            'C' => ['tanggal',      16],
            'D' => ['periode_awal', 16],
            'E' => ['periode_akhir',16],
            'F' => ['saldo_awal',   20],
            'G' => ['keterangan',   36],
            'H' => ['no_urut',      10],
        ];

        $sheet->mergeCells('A1:H1');
        $sheet->setCellValue('A1', 'TEMPLATE IMPORT DATA OPENING BALANCE — Sheet 1: Data Utama');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $sheet->mergeCells('A2:H2');
        $sheet->setCellValue('A2', 'Isi data Opening Balance di sini. Kolom no_invoice (A) WAJIB diisi secara manual. Kolom no_urut (H) WAJIB diisi sebagai referensi untuk Sheet 2 dan 3. PERHATIAN: periode_awal dan periode_akhir (D & E) mengacu pada rentang waktu invoice historis yang belum lunas — BUKAN tanggal hari ini. Lihat sheet "Petunjuk Pengisian" untuk panduan lengkap.');
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF37474F']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF1F8E9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(28);
        $sheet->getRowDimension(3)->setRowHeight(8);

        foreach ($cols as $col => [$name, $width]) {
            $sheet->setCellValue("{$col}4", $name);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle('A4:H4')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E7D32']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1B5E20']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(24);

        // Example row
        $example = [
            'A' => '[CONTOH] OB-BUDI-15012024-001',
            'B' => 'Budi Santoso',
            'C' => '2024-01-15',
            'D' => '2024-01-01',
            'E' => '2024-01-31',
            'F' => '1500000',
            'G' => 'Saldo piutang periode lalu',
            'H' => '1',
        ];
        foreach ($example as $col => $val) {
            $sheet->getCell("{$col}5")->setValueExplicit($val, DataType::TYPE_STRING);
        }
        $sheet->getStyle('A5:H5')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FFE65100']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF9C4']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFFECB3']]],
        ]);
        $sheet->getRowDimension(5)->setRowHeight(20);

        for ($row = 6; $row <= 55; $row++) {
            $bg = $row % 2 === 0 ? 'FFF5F5F5' : 'FFFFFFFF';
            $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFE0E0E0']]],
            ]);
            // Text format to prevent date/number auto-conversion
            foreach (['C', 'D', 'E', 'F', 'H'] as $col) {
                $sheet->getStyle("{$col}{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
            $sheet->getRowDimension($row)->setRowHeight(18);
        }

        $sheet->freezePane('A5');
    }

    private function buildObDetailSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Rincian Invoice Asal');

        $cols = [
            'A' => ['no_urut_ob',           14],
            'B' => ['no_invoice_asal',       24],
            'C' => ['tanggal_invoice_asal',  18],
            'D' => ['deskripsi',             32],
            'E' => ['jumlah_tagihan_asal',   20],
            'F' => ['sisa_tagihan_asal',     20],
            'G' => ['keterangan',            30],
        ];

        $sheet->mergeCells('A1:G1');
        $sheet->setCellValue('A1', 'TEMPLATE IMPORT — Sheet 2: Rincian Invoice Asal');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $sheet->mergeCells('A2:G2');
        $sheet->setCellValue('A2', 'Isi rincian Invoice Asal. Kolom no_urut_ob (A) harus sesuai dengan no_urut di Sheet 1. Opsional — dikosongkan jika tidak ada rincian.');
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
        $sheet->getStyle('A4:G4')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1976D2']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF0D47A1']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(24);

        $example = [
            'A' => '1',
            'B' => '[CONTOH] INV-2024-001',
            'C' => '2024-01-10',
            'D' => 'Penjualan Minyak Goreng',
            'E' => '2000000',
            'F' => '1500000',
            'G' => 'Cicilan sudah dibayar sebagian',
        ];
        foreach ($example as $col => $val) {
            $sheet->getCell("{$col}5")->setValueExplicit($val, DataType::TYPE_STRING);
        }
        $sheet->getStyle('A5:G5')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FFE65100']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF9C4']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFFECB3']]],
        ]);
        $sheet->getRowDimension(5)->setRowHeight(20);

        for ($row = 6; $row <= 105; $row++) {
            $bg = $row % 2 === 0 ? 'FFF5F5F5' : 'FFFFFFFF';
            $sheet->getStyle("A{$row}:G{$row}")->applyFromArray([
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFE0E0E0']]],
            ]);
            foreach (['A', 'C', 'E', 'F'] as $col) {
                $sheet->getStyle("{$col}{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
            $sheet->getRowDimension($row)->setRowHeight(18);
        }

        $sheet->freezePane('A5');
    }

    private function buildObItemSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Item Invoice Asal');

        $cols = [
            'A' => ['no_urut_ob',      14],
            'B' => ['no_invoice_asal', 24],
            'C' => ['kode_barang',     18],
            'D' => ['nama_barang',     30],
            'E' => ['qty',             10],
            'F' => ['satuan',          12],
            'G' => ['harga_satuan',    20],
            'H' => ['subtotal',        20],
            'I' => ['keterangan',      30],
        ];

        $sheet->mergeCells('A1:I1');
        $sheet->setCellValue('A1', 'TEMPLATE IMPORT — Sheet 3: Item Invoice Asal');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF6A1B9A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $sheet->mergeCells('A2:I2');
        $sheet->setCellValue('A2', 'Isi item/barang per Invoice Asal. Kolom kode_barang (C) opsional. Kolom subtotal (H) boleh dikosongkan — dihitung otomatis dari qty × harga_satuan. Opsional — dikosongkan jika tidak ada item.');
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF37474F']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF3E5F5']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(28);
        $sheet->getRowDimension(3)->setRowHeight(8);

        foreach ($cols as $col => [$name, $width]) {
            $sheet->setCellValue("{$col}4", $name);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle('A4:I4')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF7B1FA2']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF4A148C']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(24);

        $example = [
            'A' => '1',
            'B' => '[CONTOH] INV-2024-001',
            'C' => 'BRG-001',
            'D' => 'Minyak Goreng 5L',
            'E' => '100',
            'F' => 'botol',
            'G' => '50000',
            'H' => '5000000',
            'I' => 'Kualitas premium',
        ];
        foreach ($example as $col => $val) {
            $sheet->getCell("{$col}5")->setValueExplicit($val, DataType::TYPE_STRING);
        }
        $sheet->getStyle('A5:I5')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FFE65100']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF9C4']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFFECB3']]],
        ]);
        $sheet->getRowDimension(5)->setRowHeight(20);

        for ($row = 6; $row <= 205; $row++) {
            $bg = $row % 2 === 0 ? 'FFF5F5F5' : 'FFFFFFFF';
            $sheet->getStyle("A{$row}:I{$row}")->applyFromArray([
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFE0E0E0']]],
            ]);
            foreach (['A', 'E', 'G', 'H'] as $col) {
                $sheet->getStyle("{$col}{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
            $sheet->getRowDimension($row)->setRowHeight(18);
        }

        $sheet->freezePane('A5');
    }

    private function buildObInstructionSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Petunjuk Pengisian');
        $sheet->getColumnDimension('A')->setWidth(26);
        $sheet->getColumnDimension('B')->setWidth(50);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(40);

        $row = 1;

        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", 'PETUNJUK PENGISIAN — TEMPLATE IMPORT OPENING BALANCE');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(34);
        $row += 2;

        // Section: Cara Pengisian
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", '  CARA PENGISIAN');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E7D32']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++;

        $steps = [
            '1. Jangan ubah nama atau urutan kolom pada baris header (berwarna hijau/biru/ungu).',
            '2. Hapus baris [CONTOH] sebelum melakukan import data.',
            '3. SHEET 1 (Data Opening Balance): isi data OB utama. Kolom no_urut wajib diisi dengan angka unik (1, 2, 3, ...).',
            '4. SHEET 2 (Rincian Invoice Asal): opsional. Isi rincian per-invoice. Kolom no_urut_ob harus sama dengan no_urut di Sheet 1.',
            '5. SHEET 3 (Item Invoice Asal): opsional. Isi item/barang. Kolom no_urut_ob dan no_invoice_asal harus sesuai dengan Sheet 1 dan 2.',
            '6. Kolom "tanggal" (Sheet 1) diisi dengan tanggal pengajuan OB (hari ini). Kolom "periode_awal" dan "periode_akhir" diisi dengan rentang waktu invoice HISTORIS yang belum lunas — bukan tanggal hari ini. Contoh: ada tagihan Mei 2026 belum lunas → periode_awal=2026-05-01, periode_akhir=2026-05-31. Format semua tanggal: YYYY-MM-DD (contoh: 2024-01-15) atau DD-MM-YYYY (contoh: 15-01-2024).',
            '7. Angka diisi tanpa titik ribuan dan tanpa simbol Rp (contoh: 1500000, bukan Rp 1.500.000).',
            '8. nama_klien di Sheet 1 harus cocok persis dengan nama klien yang terdaftar di sistem.',
            '9. Data yang berhasil diimport akan berstatus DRAFT dan membutuhkan persetujuan.',
            '10. Import CSV hanya memproses Sheet 1 (data OB utama saja, tanpa rincian dan item).',
            '11. Simpan file sebagai .xlsx untuk import lengkap (OB + rincian + item).',
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

        // Keterangan kolom per sheet
        $sheetInfos = [
            ['Sheet 1 — Data Opening Balance', 'FF2E7D32', [
                ['no_invoice',    'Nomor Opening Balance unik',                 'Ya',       'Diisi manual, harus unik di sistem. Contoh: OB-BUDI-15012024-001'],
                ['nama_klien',    'Nama Client',                              'Ya',       'Harus cocok persis dengan nama klien di sistem'],
                ['tanggal',       'Tanggal dokumen Opening Balance',            'Ya',       'Format: YYYY-MM-DD atau DD-MM-YYYY'],
                ['periode_awal',  'Tanggal awal invoice historis yang belum lunas (bukan tanggal pengajuan)', 'Ya', 'Rentang waktu invoice LAMA di luar sistem. Contoh: invoice Jan 2024 belum lunas → isi 2024-01-01. Format: YYYY-MM-DD atau DD-MM-YYYY'],
                ['periode_akhir', 'Tanggal akhir invoice historis yang belum lunas (bukan tanggal pengajuan)', 'Ya', 'Biasanya akhir bulan invoice lama. Harus >= periode_awal. Contoh: 2024-01-31'],
                ['saldo_awal',    'Jumlah saldo awal piutang',                 'Ya',       'Angka tanpa titik ribuan. Contoh: 1500000'],
                ['keterangan',    'Catatan tambahan',                          'Opsional', 'Teks bebas, maks 500 karakter'],
                ['no_urut',       'Nomor urut sebagai referensi Sheet 2 & 3', 'Ya',       'Angka unik: 1, 2, 3, ...'],
            ]],
            ['Sheet 2 — Rincian Invoice Asal', 'FF1565C0', [
                ['no_urut_ob',           'Referensi ke no_urut di Sheet 1',  'Ya',       'Harus sama persis dengan no_urut di Sheet 1'],
                ['no_invoice_asal',      'Nomor invoice dari periode lalu',   'Ya',       'Teks bebas. Contoh: INV-2024-001'],
                ['tanggal_invoice_asal', 'Tanggal invoice asli',              'Ya',       'Format: YYYY-MM-DD atau DD-MM-YYYY'],
                ['deskripsi',            'Deskripsi tagihan',                 'Ya',       'Teks bebas, maks 255 karakter'],
                ['jumlah_tagihan_asal',  'Jumlah tagihan asli',               'Opsional', 'Jika ada item di Sheet 3, dihitung otomatis dari sum subtotal'],
                ['sisa_tagihan_asal',    'Sisa tagihan yang belum dibayar',   'Ya',       'Angka, total semua baris harus = saldo_awal OB'],
                ['keterangan',           'Catatan tambahan',                  'Opsional', 'Teks bebas'],
            ]],
            ['Sheet 3 — Item Invoice Asal', 'FF6A1B9A', [
                ['no_urut_ob',    'Referensi ke no_urut di Sheet 1',    'Ya',       'Harus ada di Sheet 1'],
                ['no_invoice_asal','Referensi ke invoice di Sheet 2',   'Ya',       'Harus ada di Sheet 2 dengan no_urut_ob yang sama'],
                ['nama_barang',   'Nama produk atau layanan',           'Ya',       'Teks bebas, maks 255 karakter'],
                ['qty',           'Jumlah/kuantitas',                   'Ya',       'Angka > 0, mendukung 3 desimal'],
                ['satuan',        'Satuan ukuran',                      'Opsional', 'Contoh: pcs, liter, kg, botol. Default: pcs'],
                ['harga_satuan',  'Harga per satuan',                   'Ya',       'Angka >= 0'],
                ['subtotal',      'Subtotal baris',                     'Opsional', 'Dihitung otomatis: qty × harga_satuan'],
                ['keterangan',    'Catatan tambahan',                   'Opsional', 'Teks bebas'],
            ]],
        ];

        foreach ($sheetInfos as [$sheetTitle, $headerColor, $colInfos]) {
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->setCellValue("A{$row}", "  KETERANGAN KOLOM — {$sheetTitle}");
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . ltrim($headerColor, '#')]],
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
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1976D2']]],
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

        // Section: Catatan Penting
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", '  CATATAN PENTING');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFC62828']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++;

        $notes = [
            '• Import bersifat INSERT ONLY — data yang sudah ada tidak akan diperbarui (tidak ada update/upsert).',
            '• Duplikat (klien + periode_awal + periode_akhir yang sama) akan DILEWATI dengan keterangan error.',
            '• Jika baris di Sheet 1 gagal, semua rincian (Sheet 2) dan item (Sheet 3) yang merujuknya ikut diabaikan.',
            '• Import CSV hanya memproses Sheet 1. Gunakan XLSX untuk import lengkap dengan rincian dan item.',
            '• Data berhasil diimport berstatus DRAFT + approval PENDING — perlu disetujui Direktur.',
        ];

        foreach ($notes as $note) {
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->setCellValue("A{$row}", "  {$note}");
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font'      => ['size' => 9, 'color' => ['argb' => 'FFC62828']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFEBEE']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFFCDD2']]],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(18);
            $row++;
        }
    }

    // ─── Private: Parse Helpers ───────────────────────────────────────────────

    private function parseObSheetRows(string $path, int $sheetIndex, string $headerMarker, int $maxCols): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheetCount  = $spreadsheet->getSheetCount();

        if ($sheetIndex >= $sheetCount) {
            return [];
        }

        $sheet       = $spreadsheet->getSheet($sheetIndex);
        $rows        = [];
        $headerFound = false;

        foreach ($sheet->getRowIterator() as $rowObj) {
            $cellIter = $rowObj->getCellIterator();
            $cellIter->setIterateOnlyExistingCells(false);

            $cells = [];
            foreach ($cellIter as $cell) {
                $cells[] = $this->xlsxObCellToString($cell);
            }

            $cells     = array_slice($cells, 0, $maxCols);
            $firstCell = trim($cells[0] ?? '');

            if (!$headerFound) {
                if (strtolower($firstCell) === strtolower($headerMarker)) {
                    $headerFound = true;
                    $rows[]      = $cells;
                }
                continue;
            }

            $rows[] = $cells;
        }

        return $rows;
    }

    private function parseObCsv(string $path): array
    {
        $rows   = [];
        $handle = fopen($path, 'r');

        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }

    private function parseObDate(mixed $val): ?string
    {
        $s = trim((string) $val);
        if ($s === '' || $s === '-') return null;

        // Try YYYY-MM-DD
        try {
            $d = \Carbon\Carbon::createFromFormat('Y-m-d', $s);
            if ($d && $d->format('Y-m-d') === $s) return $s;
        } catch (\Throwable) {}

        // Try DD-MM-YYYY
        try {
            $d = \Carbon\Carbon::createFromFormat('d-m-Y', $s);
            if ($d) return $d->format('Y-m-d');
        } catch (\Throwable) {}

        // Try DD/MM/YYYY (slash separator, common in Indonesian Excel locale)
        try {
            $d = \Carbon\Carbon::createFromFormat('d/m/Y', $s);
            if ($d && $d->format('d/m/Y') === $s) return $d->format('Y-m-d');
        } catch (\Throwable) {}

        // Fallback: numeric Excel serial date
        try {
            $d = \Carbon\Carbon::parse($s);
            return $d->format('Y-m-d');
        } catch (\Throwable) {}

        return null;
    }

    private function importObValue(mixed $val): ?string
    {
        $s = trim((string) $val);
        return ($s === '' || $s === '-') ? null : $s;
    }

    private function xlsxObCellToString(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): string
    {
        $value = $cell->getValue();

        if ($value === null) return '';
        if (is_bool($value)) return $value ? '1' : '0';

        // Convert Excel date serial numbers to YYYY-MM-DD string
        if ((is_int($value) || is_float($value)) && \PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell)) {
            $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value);
            return $dt->format('Y-m-d');
        }

        if (is_int($value)) return (string) $value;
        if (is_float($value)) {
            return fmod($value, 1.0) === 0.0
                ? sprintf('%.0f', $value)
                : (string) $value;
        }
        return trim((string) $value);
    }

    // ─── Private: Auth Helpers ────────────────────────────────────────────────

    private function findOpeningBalanceOrFail(int $id): Invoice
    {
        $invoice = $this->service->findOrFail($id);
        abort_if(!$invoice->is_opening_balance, 404, 'Opening balance tidak ditemukan');

        return $invoice;
    }

    private function authorizeViewOpeningBalance(): void
    {
        abort_if(
            !RoleHelper::canViewOpeningBalance(auth()->user()),
            403,
            'Tidak memiliki akses ke data opening balance'
        );
    }

    private function authorizeOperateOpeningBalance(): void
    {
        abort_if(
            !RoleHelper::canOperateOpeningBalance(auth()->user()),
            403,
            'Tidak memiliki akses untuk mengelola opening balance'
        );
    }

    private function authorizeApproveOpeningBalance(): void
    {
        abort_if(
            !RoleHelper::canApproveOpeningBalance(auth()->user()),
            403,
            'Tidak memiliki akses untuk menyetujui opening balance'
        );
    }
}
