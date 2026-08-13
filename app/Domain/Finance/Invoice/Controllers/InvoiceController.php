<?php

namespace App\Domain\Finance\Invoice\Controllers;

use App\Domain\Finance\Invoice\DTO\InvoiceDTO;
use App\Domain\Finance\Invoice\Requests\StoreInvoiceRequest;
use App\Domain\Finance\Invoice\Requests\UpdateInvoiceRequest;
use App\Domain\Finance\Invoice\Resources\InvoiceItemResource;
use App\Domain\Finance\Invoice\Resources\InvoiceListResource;
use App\Domain\Finance\Invoice\Resources\InvoiceResource;
use App\Domain\Finance\Invoice\Jobs\GenerateOpeningBalancePrintJob;
use App\Domain\Finance\Invoice\Services\FonnteApiClient;
use App\Domain\Finance\Invoice\Services\InvoicePrintCacheService;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Http\Controllers\Controller;
use App\Models\BulkPrintToken;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Investor;
use App\Models\KlienAr;
use App\Models\PembayaranArItem;
use App\Models\Resto;
use Carbon\Carbon;
use App\Support\Helpers\ArFilterScope;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    public function __construct(
        private readonly InvoiceService $service,
        private readonly FonnteApiClient $fonnte,
        private readonly InvoicePrintCacheService $printCache,
    ) {}

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

        $query = \App\Models\Invoice::with(['items.barang', 'resto', 'klienAr.resto'])
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

        $invoices = $invoices->map(fn($inv) => $this->mapOutstandingInvoice($inv, $barangByKode));

        return $this->successResponse($invoices);
    }

    /**
     * Versi bulk dari outstanding() — 1 query untuk banyak Client sekaligus,
     * dipakai khusus oleh "Muat Client" massal (Opening Balance) untuk
     * menghindari N request paralel per Client yang bisa membebani backend.
     * Selalu dibatasi ke bulan lalu (relatif terhadap `tanggal`) karena itu
     * memang tujuan spesifik pemanggilnya, bukan pengganti outstanding() biasa.
     */
    public function outstandingBulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'klien_ar_ids'   => ['required', 'array', 'min:1', 'max:50'],
            'klien_ar_ids.*' => ['integer', 'exists:tb_klien_ar,id'],
            'tanggal'        => ['nullable', 'date'],
        ]);

        foreach ($validated['klien_ar_ids'] as $klienArId) {
            $this->authorizeKlienArOwnership((int) $klienArId);
        }

        $acuan = !empty($validated['tanggal']) ? Carbon::parse($validated['tanggal']) : Carbon::now();
        $bulanLalu = $acuan->copy()->subMonthNoOverflow();

        $invoices = \App\Models\Invoice::with(['items.barang', 'resto', 'klienAr.resto'])
            ->whereIn('klien_ar_id', $validated['klien_ar_ids'])
            ->whereIn('status', ['TERKIRIM', 'SEBAGIAN'])
            ->where('is_opening_balance', false)
            ->whereBetween('tanggal_invoice', [
                $bulanLalu->copy()->startOfMonth()->toDateString(),
                $bulanLalu->copy()->endOfMonth()->toDateString(),
            ])
            ->orderBy('tanggal_invoice')
            ->orderBy('id')
            ->get();

        $kodeBarangTanpaId = $invoices->flatMap(fn($inv) => $inv->items)
            ->whereNull('barang_id')
            ->pluck('kode_barang')
            ->filter()
            ->unique()
            ->values();

        $barangByKode = $kodeBarangTanpaId->isNotEmpty()
            ? \App\Models\Barang::whereIn('kode_barang', $kodeBarangTanpaId)->get()->keyBy('kode_barang')
            : collect();

        $grouped = $invoices
            ->map(fn($inv) => $this->mapOutstandingInvoice($inv, $barangByKode))
            ->groupBy('klien_ar_id')
            ->map(fn($group) => $group->values());

        return $this->successResponse($grouped);
    }

    private function mapOutstandingInvoice(\App\Models\Invoice $inv, \Illuminate\Support\Collection $barangByKode): array
    {
        $resto = $inv->resto ?? $inv->klienAr?->resto;

        // Untuk klien PT: resto tidak ada di header invoice/klien,
        // tapi bisa ada di level item (kode_resto/nama_resto per item).
        $kodeResto = $resto?->kode_resto
            ?? $inv->items->first(fn($item) => filled($item->kode_resto))?->kode_resto
            ?? '';
        $namaResto = $resto?->nama_resto
            ?? $inv->items->first(fn($item) => filled($item->nama_resto))?->nama_resto
            ?? '';

        return [
            'id'              => $inv->id,
            'klien_ar_id'     => $inv->klien_ar_id,
            'no_invoice'      => $inv->no_invoice,
            'tanggal_invoice' => $inv->tanggal_invoice?->toDateString(),
            'subtotal'        => (float) $inv->subtotal,
            'total_tagihan'   => (float) $inv->total_tagihan,
            'sisa_tagihan'    => max(0.0, (float) $inv->subtotal - (float) $inv->total_pembayaran - (float) $inv->total_penyesuaian),
            'status'          => $inv->status,
            'keterangan'      => $inv->keterangan,
            'kode_resto'      => $kodeResto,
            'nama_resto'      => $namaResto,
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
        ];
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

    private function rekapKlienFilterRules(): array
    {
        return [
            'klien_ar_id'   => ['nullable', 'integer', 'exists:tb_klien_ar,id'],
            'periode_bulan' => ['nullable', 'integer', 'between:1,12'],
            'periode_tahun' => ['nullable', 'integer', 'between:2000,2100'],
            'segment'       => ['nullable', 'in:B2B,B2C,ALL'],
        ];
    }

    /** Kolom Rekap Klien — sama persis di XLSX & CSV, disamakan dengan ExportDataWorkbookService::rekapKlienSection(). */
    private const REKAP_KLIEN_HEADERS = [
        'No', 'Nama Klien', 'Kode Resto', 'Nama Resto', 'PIC AR', 'Entitas', 'Jml Invoice',
        'Total Tagihan', 'Total Terbayar', 'Sisa Piutang', 'Overdue', 'Collection Rate',
        'Draft', 'Terkirim', 'Sebagian', 'Lunas',
    ];

    private function rekapKlienRowValues(array $row, int $index): array
    {
        return [
            $index + 1,
            $row['nama_klien'] ?? '',
            // Klien B2B (PT) tidak punya 1 resto tunggal — 1 PT bisa menaungi banyak
            // outlet/resto sekaligus (ditagih dalam 1 invoice konsolidasi), jadi
            // kode_resto/nama_resto selalu null utk tipe ini. Fallback ini SENGAJA
            // cuma di export (bukan di InvoiceRepository::getRekapKlien()), karena
            // data mentahnya juga dipakai tampilan web yang men-treat null sebagai
            // "sembunyikan bagian resto" — lihat RekapKlien.vue.
            $row['kode_resto'] ?? '-',
            $row['nama_resto'] ?? '-',
            $row['pic_ar'] ?? '',
            $row['perusahaan'] ?? '',
            $row['total_invoice'] ?? 0,
            $row['total_tagihan'] ?? 0,
            $row['total_pembayaran'] ?? 0,
            $row['sisa_tagihan'] ?? 0,
            $row['overdue_amount'] ?? 0,
            ($row['collection_rate'] ?? 0) . '%',
            $row['draft'] ?? 0,
            $row['terkirim'] ?? 0,
            $row['sebagian'] ?? 0,
            $row['lunas'] ?? 0,
        ];
    }

    /**
     * Jumlah baris klien yang akan dihasilkan export untuk filter yang sama — dipakai FE
     * untuk peringatan real-time XLSX vs CSV di modal Export. Rekap Klien 1 baris = 1 klien,
     * jadi jumlahnya realistis selalu jauh di bawah ambang peringatan.
     */
    public function exportRekapKlienRowCount(Request $request): JsonResponse
    {
        $request->validate($this->rekapKlienFilterRules());

        $filters = $request->only(['klien_ar_id', 'periode_bulan', 'periode_tahun', 'segment']);
        ArFilterScope::apply($filters, auth()->user());

        $rows = $this->service->getRekapKlien($filters);

        return $this->successResponse(['row_count' => count($rows)]);
    }

    public function exportRekapKlien(Request $request): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $request->validate($this->rekapKlienFilterRules());

        $filters = $request->only(['klien_ar_id', 'periode_bulan', 'periode_tahun', 'segment']);
        ArFilterScope::apply($filters, auth()->user());

        $rows   = $this->service->getRekapKlien($filters);
        $format = strtolower((string) $request->query('format', 'xlsx'));

        return $format === 'csv' ? $this->streamRekapKlienCsv($rows) : $this->streamRekapKlienXlsx($rows);
    }

    /** Rekap Klien cuma 1 level (tanpa baris detail per invoice), jadi CSV-nya 1 tabel flat biasa. */
    private function streamRekapKlienCsv(array $rows): StreamedResponse
    {
        $dataRows = [];
        foreach ($rows as $index => $row) {
            $dataRows[] = $this->rekapKlienRowValues($row, $index);
        }

        return response()->streamDownload(function () use ($dataRows) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
            fputcsv($handle, self::REKAP_KLIEN_HEADERS, ';');

            foreach ($dataRows as $row) {
                fputcsv($handle, $row, ';');
            }

            fclose($handle);
        }, 'Rekap Piutang per Klien-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function streamRekapKlienXlsx(array $rows): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rekap Piutang per Klien');

        $cols    = range('A', 'P'); // 16 kolom, sinkron dengan REKAP_KLIEN_HEADERS
        $lastCol = 'P';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'REKAP PIUTANG PER KLIEN');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', 'Diekspor: ' . now()->format('d-m-Y H:i'));
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF455A64']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE3F2FD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(18);
        $sheet->getRowDimension(3)->setRowHeight(6);

        foreach (array_combine($cols, self::REKAP_KLIEN_HEADERS) as $col => $label) {
            $sheet->setCellValue("{$col}4", $label);
        }
        $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1976D2']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1565C0']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(22);

        $numericCols = ['G', 'H', 'I', 'J', 'K', 'M', 'N', 'O', 'P']; // Jml Invoice, Total Tagihan..Overdue, Draft..Lunas

        $rowNum = 5;
        foreach ($rows as $index => $row) {
            $bg = $rowNum % 2 === 0 ? 'FFE3F2FD' : 'FFFFFFFF';

            foreach (array_combine($cols, $this->rekapKlienRowValues($row, $index)) as $col => $val) {
                $type = in_array($col, $numericCols, true) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING;
                $sheet->getCell("{$col}{$rowNum}")->setValueExplicit($val, $type);
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
            $sheet->getStyle('G5:K' . ($rowNum - 1))->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('M5:P' . ($rowNum - 1))->getNumberFormat()->setFormatCode('#,##0');
        }

        $sheet->freezePane('A5');

        $temp = tempnam(sys_get_temp_dir(), 'rk_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'Rekap Piutang per Klien-' . now()->format('Ymd-His') . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
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
        $request->validate(['status' => ['required', 'in:DRAFT,TERKIRIM']]);
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

    /** Ringkasan per invoice — dipakai Sheet 1 XLSX ("Invoice") & blok kiri CSV. */
    private const SHEET_INVOICE_HEADERS = [
        'No Invoice', 'Klien', 'Entitas', 'Tanggal Invoice',
        'Subtotal', 'Tagihan Sebelumnya', 'Total Tagihan', 'Total Pembayaran', 'Sisa Tagihan', 'Status',
    ];

    /** Detail per item barang — dipakai Sheet 2 XLSX ("Detail Invoice") & blok kanan CSV. */
    private const SHEET_DETAIL_HEADERS = [
        'Nomor Invoice 1', 'Klien', 'Resto', 'Kode Resto', 'Stokis', 'Entitas', 'Tanggal Invoice',
        'Kode Barang', 'Nama Barang', 'Satuan', 'QTY', 'Harga Satuan', 'Total Item', 'Nomor Invoice 2',
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
            'no_invoice'       => $meta['no_invoice']      ?? '-',
            'no_invoice_resto' => $d->no_invoice_resto     ?? '-',
            'klien'            => $meta['klien']           ?? '-',
            'resto'            => $restoNama,
            'kode_resto'       => $resolvedKode             ?? '-',
            'stokis'           => $stokis,
            'entitas'          => $meta['entitas']         ?? '-',
            'tanggal_invoice'  => $meta['tanggal_invoice'] ?? '-',
            'status'           => $meta['status']          ?? '-',
            'kode_barang'      => $d->kode_barang ?? $d->barang?->kode_barang ?? '-',
            'nama_barang'      => $d->nama_barang,
            'satuan'           => $d->satuan ?? '-',
            'qty'              => (float) $d->qty,
            'harga_satuan'     => (float) $d->harga_satuan,
            'total_item'       => (float) $d->subtotal,
        ];
    }

    /**
     * Reorder hasil resolveExportRow() sesuai SHEET_DETAIL_HEADERS — dipakai bareng
     * oleh Sheet 2 XLSX ("Detail Invoice") dan blok kanan CSV supaya urutan kolom
     * cuma didefinisikan sekali. Kolom 'status' sengaja tidak diikutkan.
     */
    private function detailRowValues(array $row): array
    {
        return [
            $row['no_invoice_resto'],
            $row['klien'],
            $row['resto'],
            $row['kode_resto'],
            $row['stokis'],
            $row['entitas'],
            $row['tanggal_invoice'],
            $row['kode_barang'],
            $row['nama_barang'],
            $row['satuan'],
            $row['qty'],
            $row['harga_satuan'],
            $row['total_item'],
            $row['no_invoice'],
        ];
    }

    /**
     * Baris ringkasan per invoice sesuai SHEET_INVOICE_HEADERS — dipakai bareng oleh
     * Sheet 1 XLSX ("Invoice") dan blok kiri CSV. $invoices dari getAllForExport()
     * sudah eager-load klienAr.resto.perusahaan/resto/perusahaan, jadi tidak ada
     * query tambahan di sini.
     */
    private function invoiceSummaryRowValues(Invoice $inv): array
    {
        $entitasP = $inv->klienAr?->resto?->perusahaan ?? $inv->perusahaan;

        return [
            $inv->no_invoice,
            $inv->klienAr?->nama_klien ?? '-',
            $entitasP?->nama_perusahaan ?? '-',
            $inv->tanggal_invoice?->format('d-m-Y') ?? '-',
            (float) $inv->subtotal,
            (float) $inv->tagihan_periode_sebelumnya,
            (float) $inv->total_tagihan,
            (float) $inv->total_pembayaran,
            (float) $inv->sisa_tagihan,
            $inv->status,
        ];
    }

    public function exportExcel(Request $request): StreamedResponse|BinaryFileResponse
    {
        $format = strtolower((string) $request->query('format', 'csv'));

        return $format === 'xlsx' ? $this->streamXlsxExport($request) : $this->streamCsvExport($request);
    }

    /**
     * Export CSV — CSV tidak punya konsep sheet, jadi blok ringkasan invoice (kiri) dan
     * blok detail item (kanan, dipisah 1 kolom kosong) ditulis BERDAMPINGAN pada baris
     * yang sama, meniru tampilan 2-sheet versi XLSX (lihat streamXlsxExport()).
     * Blok kiri (ringkasan per invoice) di-buffer penuh di memori — amannya sama seperti
     * buildExportInvoiceMeta(), jumlah invoice selalu jauh lebih kecil dari jumlah item.
     * Blok kanan (detail per item) tetap di-stream per-batch invoice_id supaya memori
     * tetap flat untuk data puluhan-ratusan ribu baris.
     */
    private function streamCsvExport(Request $request): StreamedResponse
    {
        $filters      = $this->resolveExportFilters($request);
        $invoices     = $this->service->getAllForExport($filters);
        $invoiceIds   = $invoices->pluck('id');
        $invoiceMeta  = $this->buildExportInvoiceMeta($invoices);
        $restosByKode = $this->buildExportRestoLookup($invoiceIds, $invoiceMeta);

        $summaryRows  = $invoices->map(fn($inv) => $this->invoiceSummaryRowValues($inv))->values();
        $summaryCount = $summaryRows->count();
        $summaryBlank = array_fill(0, count(self::SHEET_INVOICE_HEADERS), '');
        $detailBlank  = array_fill(0, count(self::SHEET_DETAIL_HEADERS), '');
        $header       = array_merge(self::SHEET_INVOICE_HEADERS, [''], self::SHEET_DETAIL_HEADERS);

        return response()->streamDownload(function () use (
            $invoiceIds, $invoiceMeta, $restosByKode, $summaryRows, $summaryCount, $summaryBlank, $detailBlank, $header
        ) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
            // Titik koma, bukan koma — locale Excel Indonesia pakai ";" sebagai list
            // separator sistem, jadi file terbuka langsung terbagi rapi per kolom.
            fputcsv($handle, $header, ';');

            $rowIndex = 0;

            $invoiceIds->chunk(500)->each(function ($batchIds) use ($handle, $invoiceMeta, $restosByKode, $summaryRows, $summaryCount, $summaryBlank, &$rowIndex) {
                $this->exportItemQuery($batchIds)->get()->each(function ($d) use ($handle, $invoiceMeta, $restosByKode, $summaryRows, $summaryCount, $summaryBlank, &$rowIndex) {
                    $meta   = $invoiceMeta[$d->invoice_id] ?? [];
                    $detail = $this->detailRowValues($this->resolveExportRow($d, $meta, $restosByKode));
                    $left   = $rowIndex < $summaryCount ? $summaryRows[$rowIndex] : $summaryBlank;

                    fputcsv($handle, array_merge($left, [''], $detail), ';');
                    $rowIndex++;
                });
            });

            // Kasus langka: invoice tanpa item, baris ringkasannya belum sempat ditulis
            // di loop atas — tulis sisanya di sini dengan blok detail kosong.
            while ($rowIndex < $summaryCount) {
                fputcsv($handle, array_merge($summaryRows[$rowIndex], [''], $detailBlank), ';');
                $rowIndex++;
            }

            fclose($handle);
        }, 'Data Tagihan Invoice-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** Tulis header row (bold + fill biru) & freeze pane baris pertama data — dipakai tiap sheet. */
    private function writeXlsxHeaderRow(Worksheet $sheet, array $cols, array $labels, string $lastCol): void
    {
        foreach (array_combine($cols, $labels) as $col => $label) {
            $sheet->setCellValueExplicit("{$col}1", $label, DataType::TYPE_STRING);
        }
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1565C0']],
        ]);
        $sheet->freezePane('A2');
    }

    /**
     * Export XLSX asli (PhpSpreadsheet), 2 sheet terpisah: "Invoice" (ringkasan per invoice)
     * dan "Detail Invoice" (per item barang). Styling SENGAJA cuma di header row tiap sheet —
     * jangan tiru pola styling per-sel di dalam loop seperti exportB2BDelivery(), itu justru
     * penyebab lama versi Excel 2-sheet dulu diganti CSV (lihat riwayat exportExcel()).
     * Direkomendasikan hanya untuk data <= ~13.000 baris (lihat exportRowCount() + peringatan
     * di FE), tapi tidak diblokir keras di sini kalau user tetap memilih XLSX untuk data besar.
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

        $spreadsheet = new Spreadsheet();

        // ─── Sheet 1: Invoice (ringkasan per invoice) ───
        $invoiceCols    = range('A', 'J'); // 10 kolom, sinkron dengan SHEET_INVOICE_HEADERS
        $invoiceLastCol = 'J';

        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Invoice');
        $this->writeXlsxHeaderRow($sheet1, $invoiceCols, self::SHEET_INVOICE_HEADERS, $invoiceLastCol);

        $rowNum1 = 2;
        foreach ($invoices as $inv) {
            foreach (array_combine($invoiceCols, $this->invoiceSummaryRowValues($inv)) as $col => $val) {
                $type = in_array($col, ['E', 'F', 'G', 'H', 'I'], true) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING;
                $sheet1->getCell("{$col}{$rowNum1}")->setValueExplicit($val, $type);
            }
            $rowNum1++;
        }
        if ($rowNum1 > 2) {
            $sheet1->getStyle('E2:I' . ($rowNum1 - 1))->getNumberFormat()->setFormatCode('#,##0');
        }

        // ─── Sheet 2: Detail Invoice (per item barang) ───
        $detailCols    = range('A', 'N'); // 14 kolom, sinkron dengan SHEET_DETAIL_HEADERS
        $detailLastCol = 'N';

        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Detail Invoice');
        $this->writeXlsxHeaderRow($sheet2, $detailCols, self::SHEET_DETAIL_HEADERS, $detailLastCol);

        $numericCols = ['K', 'L', 'M']; // QTY, Harga Satuan, Total Item
        $rowNum2     = 2;

        $invoiceIds->chunk(500)->each(function ($batchIds) use ($sheet2, &$rowNum2, $invoiceMeta, $restosByKode, $detailCols, $numericCols) {
            $this->exportItemQuery($batchIds)->get()->each(function ($d) use ($sheet2, &$rowNum2, $invoiceMeta, $restosByKode, $detailCols, $numericCols) {
                $meta = $invoiceMeta[$d->invoice_id] ?? [];
                $row  = $this->detailRowValues($this->resolveExportRow($d, $meta, $restosByKode));

                foreach (array_combine($detailCols, $row) as $col => $val) {
                    $type = in_array($col, $numericCols, true) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING;
                    $sheet2->getCell("{$col}{$rowNum2}")->setValueExplicit($val, $type);
                }
                $rowNum2++;
            });
        });

        if ($rowNum2 > 2) {
            $sheet2->getStyle('K2:M' . ($rowNum2 - 1))->getNumberFormat()->setFormatCode('#,##0');
        }

        $spreadsheet->setActiveSheetIndex(0);

        $temp     = tempnam(sys_get_temp_dir(), 'export_invoice_') . '.xlsx';
        $filename = 'Data Tagihan Invoice-' . now()->format('Ymd-His') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    public function publicPrint(string $token): Response|StreamedResponse
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
            'klienAr.resto.investor',
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

        $filename = 'Invoice-' . str_replace(['/', '\\', ' '], '-', $invoice->no_invoice) . '.pdf';

        // Kalau sudah pernah di-generate (biasanya lewat tombol Cetak internal
        // sebelum di-share), langsung sajikan dari cache — link WA jadi instan.
        $version = null;
        if ($invoice->is_opening_balance) {
            $version = $this->printCache->resolveVersion($invoice);
            if ($this->printCache->isReady($invoice->id, $version)) {
                return $this->printCache->response($invoice->id, $version, $filename);
            }
        }

        // Belum ada cache: generate sinkron seperti sebelumnya (perilaku link
        // publik tidak berubah/tidak berisiko), tapi hasilnya disimpan juga
        // supaya percobaan berikutnya (share link maupun tombol Cetak) instan.
        [$regularInvoicesInPeriod, $regularInvoicesSignatureData] = $this->service
            ->buildOpeningBalanceRegularInvoicesPrintData($invoice);

        $this->service->attachPrintItems($invoice);
        $signatureData = $this->service->buildSignatureData($invoice);

        // compact() sengaja tidak dipakai di dalam fn() ini — lihat komentar serupa
        // di GenerateOpeningBalancePrintJob::handle().
        $viewData = [
            'invoice'                      => $invoice,
            'signatureData'                => $signatureData,
            'regularInvoicesInPeriod'      => $regularInvoicesInPeriod,
            'regularInvoicesSignatureData' => $regularInvoicesSignatureData,
        ];

        $pdfBinary = $this->printCache->withBoostedMemoryLimit(
            fn () => Pdf::loadView('finance.invoice-print', $viewData)
                ->setPaper('a4', 'portrait')
                ->setOptions([
                    'isHtml5ParserEnabled' => true,
                    'isRemoteEnabled'      => false,
                    'defaultFont'          => 'Arial',
                    'dpi'                  => 96,
                ])
                ->output()
        );

        if ($invoice->is_opening_balance && $version) {
            $this->printCache->store($invoice->id, $version, $pdfBinary);
        }

        return response($pdfBinary, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    public function print(Request $request, int $id): Response|StreamedResponse|JsonResponse|string
    {
        $invoice = $this->service->findForPrintOrFail($id);

        $this->authorizeInvoiceAccess($invoice);

        abort_if(
            $invoice->requiresApproval() && !$invoice->isApprovedForFinanceFlow(),
            422,
            'Opening balance belum disetujui, dokumen belum dapat dicetak'
        );

        $filename = 'Invoice-' . str_replace(['/', '\\', ' '], '-', $invoice->no_invoice) . '.pdf';

        // OB bisa jadi puluhan-ratusan halaman replika invoice reguler (lihat
        // catatan performa cetak Opening Balance) — generate PDF-nya dipindah ke
        // background job + di-cache di disk, supaya tidak terikat 1 request/
        // timeout web-server. Mode ?html= (preview developer) & invoice biasa
        // tetap sinkron seperti sebelumnya karena sudah cepat.
        if ($invoice->is_opening_balance && !$request->has('html')) {
            $version = $this->printCache->resolveVersion($invoice);

            if ($this->printCache->isReady($invoice->id, $version)) {
                return $this->printCache->response($invoice->id, $version, $filename);
            }

            if ($this->printCache->markDispatched($invoice->id, $version)) {
                $this->printCache->clearFailureMessage($invoice->id, $version);
                GenerateOpeningBalancePrintJob::dispatch($invoice->id, $version);
            }

            return response()->json(['status' => 'processing'], 202);
        }

        [$regularInvoicesInPeriod, $regularInvoicesSignatureData] = $this->service
            ->buildOpeningBalanceRegularInvoicesPrintData($invoice);

        $this->service->attachPrintItems($invoice);
        $signatureData = $this->service->buildSignatureData($invoice);

        if ($request->has('html')) {
            return view('finance.invoice-print', compact('invoice', 'signatureData', 'regularInvoicesInPeriod', 'regularInvoicesSignatureData'))->render();
        }

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
     * Dipoll FE saat print() membalas 202 (job masih diproses di background).
     */
    public function printStatus(int $id): JsonResponse
    {
        $invoice = $this->service->findForPrintOrFail($id);

        $this->authorizeInvoiceAccess($invoice);

        if (!$invoice->is_opening_balance) {
            return response()->json(['status' => 'ready']);
        }

        $version = $this->printCache->resolveVersion($invoice);

        if ($this->printCache->isReady($invoice->id, $version)) {
            return response()->json(['status' => 'ready']);
        }

        $failureMessage = $this->printCache->getFailureMessage($invoice->id, $version);
        if ($failureMessage) {
            return response()->json(['status' => 'failed', 'message' => $failureMessage]);
        }

        return response()->json(['status' => 'processing']);
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
     * Blast pesan WA ke banyak penerima sekaligus lewat Fonnte. Pesan sudah
     * dibangun penuh di FE (template Bahasa Indonesia tetap di client) — di
     * sini backend cuma jadi proxy server-side supaya token Fonnte tidak
     * pernah terekspos ke browser, lalu loop 1 call per penerima karena tiap
     * penerima punya isi pesan berbeda (Fonnte hanya mendukung 1 message yang
     * sama untuk semua target dalam 1 call).
     *
     * Token yang dipakai per penerima BUKAN 1 token perusahaan bersama —
     * setiap klien punya PIC AR sendiri (klien_ar.karyawan_ar_id), dan tiap
     * PIC punya device WA (token Fonnte) pribadinya sendiri. Klien yang
     * PIC-nya belum setup device di-skip (bukan fallback ke token lain).
     */
    public function shareBlast(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipients'                  => ['required', 'array', 'min:1', 'max:30'],
            'recipients.*.target'         => ['required', 'string', 'regex:/^\d{8,15}$/'],
            'recipients.*.message'        => ['required', 'string', 'max:60000'],
            'recipients.*.label'          => ['nullable', 'string', 'max:255'],
            'recipients.*.klien_ar_id'    => ['nullable', 'integer'],
            'recipients.*.invoice_ids'    => ['nullable', 'array'],
            'recipients.*.invoice_ids.*'  => ['integer'],
        ]);

        Log::info('WA blast diminta', [
            'user_id'    => auth()->id(),
            'recipients' => array_map(
                fn ($r) => [
                    'target' => $r['target'],
                    'label' => $r['label'] ?? null,
                    'klien_ar_id' => $r['klien_ar_id'] ?? null,
                    'invoice_ids' => $r['invoice_ids'] ?? [],
                ],
                $data['recipients']
            ),
        ]);

        $klienArIds = collect($data['recipients'])->pluck('klien_ar_id')->filter()->unique()->values();
        $tokenByKlienArId = KlienAr::whereIn('id', $klienArIds)
            ->with(['karyawanAr:id', 'karyawanAr.user:id,karyawan_id,fonnte_token'])
            ->get()
            ->mapWithKeys(fn ($k) => [$k->id => $k->karyawanAr?->user?->fonnte_token])
            ->all();

        $toSend  = [];
        $skipped = [];
        foreach ($data['recipients'] as $r) {
            $token = $tokenByKlienArId[$r['klien_ar_id'] ?? 0] ?? null;

            if (!$token) {
                $skipped[] = [
                    'target'    => $r['target'],
                    'label'     => $r['label'] ?? $r['target'],
                    'success'   => false,
                    'detail'    => 'PIC AR untuk klien ini belum setup device WhatsApp (token Fonnte kosong)',
                    'fonnte_id' => null,
                ];

                continue;
            }

            $toSend[] = [...$r, 'token' => $token];
        }

        $results = array_merge($this->fonnte->blast($toSend), $skipped);
        $sent    = count(array_filter($results, fn ($r) => $r['success']));

        return $this->successResponse([
            'total'   => count($results),
            'sent'    => $sent,
            'failed'  => count($results) - $sent,
            'results' => $results,
        ], 'Blast selesai');
    }

    /**
     * Endpoint publik (signed URL, tanpa auth) untuk PDF gabungan bulk print
     * per investor. Himpunan invoice_id dibaca dari token (snapshot saat
     * link dibuat), tapi nilai tiap invoice selalu diambil ulang dari DB
     * supaya statusnya terkini.
     */
    public function publicBulkB2CPrint(string $token): Response
    {
        $bulkToken = BulkPrintToken::find($token);
        abort_if(!$bulkToken, 404, 'Dokumen tidak ditemukan atau tautan tidak valid');

        // Token cuma kunci pencarian pendek (UUID) — payload asli (ID saja,
        // bukan nama/tanggal) hidup di tb_bulk_print_tokens. Nama investor/
        // klien anchor/PIC AR & rentang tanggal diturunkan ulang dari invoice
        // hasil fetch di bawah.
        $raw = $bulkToken->payload;
        $invoiceIds = $raw['i'] ?? [];
        abort_if(empty($invoiceIds), 404, 'Dokumen tidak ditemukan');

        $invoices = $this->service->getBulkB2CInvestorInvoices(
            investorIds: null,
            onlyInvoiceIds: $invoiceIds,
        );

        abort_if($invoices->isEmpty(), 404, 'Dokumen tidak ditemukan');

        $investor    = Investor::find($raw['v'] ?? null);
        $klienAnchor = KlienAr::with('karyawanAr')->find($raw['k'] ?? null);

        $payload = [
            'investor_nama'     => $investor?->nama_investor,
            'klien_anchor_nama' => $klienAnchor?->nama_klien,
            'pic_ar_nama'       => $klienAnchor?->karyawanAr?->nama_karyawan,
            'tanggal_dari'      => $invoices->min('tanggal_invoice')?->format('Y-m-d'),
            'tanggal_sampai'    => $invoices->max('tanggal_invoice')?->format('Y-m-d'),
        ];

        $invoices->each(fn($inv) => $this->service->attachPrintItems($inv));
        $signaturesById = $invoices
            ->mapWithKeys(fn($inv) => [$inv->id => $this->service->buildSignatureData($inv)])
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
                'no_wa'      => $anchor->klienAr?->resolveContactPhone(),
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
            // Payload (ID saja, key 1 huruf) disimpan di DB, bukan di dalam
            // token — supaya panjang link konstan (UUID) berapa pun jumlah
            // invoice yang digabung. Tanggal ditampilkan ulang di
            // publicBulkB2CPrint() dari invoice hasil fetch.
            $bulkToken = BulkPrintToken::create([
                'payload' => [
                    'i' => $invoices->pluck('id')->all(),
                    'v' => $investor->id,
                    'k' => $anchor->klienAr?->id,
                ],
            ]);

            $result['share_url'] = URL::temporarySignedRoute(
                'invoice.bulk-b2c-print', now()->addDays(30), ['token' => $bulkToken->token]
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

}
