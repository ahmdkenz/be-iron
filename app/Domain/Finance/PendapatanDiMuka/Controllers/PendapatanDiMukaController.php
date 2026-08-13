<?php

namespace App\Domain\Finance\PendapatanDiMuka\Controllers;

use App\Domain\Finance\PendapatanDiMuka\Services\PendapatanDiMukaService;
use App\Domain\Notification\Services\FinanceNotificationService;
use App\Http\Controllers\Controller;
use App\Models\BankStatementDetail;
use App\Models\KlienAr;
use App\Models\PendapatanDiMuka;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PendapatanDiMukaController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PendapatanDiMukaService $service,
        private readonly FinanceNotificationService $financeNotificationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'tanggal_dari'   => ['nullable', 'date'],
            'tanggal_sampai' => ['nullable', 'date', 'after_or_equal:tanggal_dari'],
            'investor_id'    => ['nullable', 'integer', 'exists:tb_investor,id'],
            'klien_ar_id'    => ['nullable', 'integer', 'exists:tb_klien_ar,id'],
            'status'         => ['nullable', 'in:AKTIF,DIBATALKAN,TERPAKAI'],
            'per_page'       => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filters = $request->only([
            'tanggal_dari', 'tanggal_sampai', 'investor_id', 'klien_ar_id', 'status', 'per_page',
        ]);
        $this->applyPicArScope($filters, $request);

        $result = $this->service->getReport($filters);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data'    => $result['data']->items(),
            'meta'    => $result['meta'],
            'summary' => $result['summary'],
        ]);
    }

    public function store(Request $request, BankStatementDetail $detail): JsonResponse
    {
        $request->validate([
            'jumlah'             => ['required', 'numeric', 'min:0.01'],
            'tanggal_pencatatan' => ['required', 'date'],
            'keterangan'         => ['nullable', 'string', 'max:500'],
        ]);

        $this->authorizeKlienArOwnership($detail->pembayaranAr?->invoice?->klienAr);

        $pdm = $this->service->store(
            $detail,
            (float) $request->jumlah,
            $request->keterangan,
            $request->tanggal_pencatatan,
        );

        return $this->createdResponse(
            $this->formatPdm($pdm),
            'Pendapatan di Muka berhasil dicatat.'
        );
    }

    public function cancel(PendapatanDiMuka $pdm): JsonResponse
    {
        $pdm->loadMissing('klienAr');
        $this->authorizeKlienArOwnership($pdm->klienAr);

        $klienArId = $pdm->klien_ar_id;
        $namaKlien = $pdm->klienAr?->nama_klien;

        $this->service->cancel($pdm);

        $this->financeNotificationService->bankReconciliationAction(
            'batal_pdm',
            'Pendapatan di Muka dibatalkan',
            sprintf('%s membatalkan Pendapatan di Muka untuk klien %s.', auth()->user()?->name ?? '-', $namaKlien ?? '-'),
            klienArId: $klienArId,
        );

        return $this->successResponse(null, 'Pendapatan di Muka berhasil dibatalkan.');
    }

    public function gunakan(Request $request, PendapatanDiMuka $pdm): JsonResponse
    {
        $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:tb_invoice,id'],
            'jumlah'     => ['required', 'numeric', 'min:0.01'],
            'keterangan' => ['nullable', 'string', 'max:500'],
        ]);

        $this->authorizeKlienArOwnership($pdm->loadMissing('klienAr')->klienAr);

        $this->service->gunakan(
            $pdm,
            (int) $request->invoice_id,
            (float) $request->jumlah,
            $request->keterangan,
        );

        return $this->successResponse(null, 'PDM berhasil digunakan untuk melunasi invoice.');
    }

    private function exportFilterRules(): array
    {
        return [
            'tanggal_dari'   => ['nullable', 'date'],
            'tanggal_sampai' => ['nullable', 'date', 'after_or_equal:tanggal_dari'],
            'investor_id'    => ['nullable', 'integer', 'exists:tb_investor,id'],
            'klien_ar_id'    => ['nullable', 'integer', 'exists:tb_klien_ar,id'],
            'status'         => ['nullable', 'in:AKTIF,DIBATALKAN,TERPAKAI'],
        ];
    }

    /**
     * Jumlah baris yang akan dihasilkan export untuk filter yang sama — dipakai
     * FE untuk peringatan real-time XLSX vs CSV di modal Export.
     */
    public function exportRowCount(Request $request): JsonResponse
    {
        $request->validate($this->exportFilterRules());

        $filters = $request->only(['tanggal_dari', 'tanggal_sampai', 'investor_id', 'klien_ar_id', 'status']);
        $this->applyPicArScope($filters, $request);

        return $this->successResponse(['row_count' => $this->service->countAll($filters)]);
    }

    public function exportExcel(Request $request): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $request->validate($this->exportFilterRules());

        $filters = $request->only(['tanggal_dari', 'tanggal_sampai', 'investor_id', 'klien_ar_id', 'status']);
        $this->applyPicArScope($filters, $request);
        $rows    = $this->service->getAll($filters);

        $format = strtolower((string) $request->query('format', 'xlsx'));

        return $format === 'csv'
            ? $this->streamCsvExport($filters, $rows)
            : $this->streamXlsxExport($filters, $rows);
    }

    /**
     * Export CSV — kolom persis sama seperti sheet XLSX-nya (streamXlsxExport()),
     * plus baris total di akhir.
     */
    private function streamCsvExport(array $filters, \Illuminate\Database\Eloquent\Collection $rows): StreamedResponse
    {
        $totalAktif = $rows->where('status', 'AKTIF')->sum('jumlah');
        $headers    = ['No', 'Tanggal Pencatatan', 'Klien', 'Investor', 'No Ref Sumber', 'Jumlah PDM', 'Status', 'Keterangan'];

        return response()->streamDownload(function () use ($rows, $headers, $totalAktif) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
            fputcsv($handle, $headers, ';');

            foreach ($rows as $i => $pdm) {
                fputcsv($handle, [
                    $i + 1,
                    $pdm->tanggal_pencatatan?->format('Y-m-d') ?? '',
                    $pdm->klienAr?->nama_klien ?? '',
                    $pdm->investor?->nama_investor ?? '-',
                    $pdm->sumberPembayaran?->no_referensi ?? '',
                    (float) $pdm->jumlah,
                    $pdm->status,
                    $pdm->keterangan ?? '',
                ], ';');
            }

            fputcsv($handle, ['', '', '', '', 'TOTAL PDM AKTIF', $totalAktif, '', ''], ';');
            fclose($handle);
        }, 'pendapatan-di-muka-' . now()->format('Ymd') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function streamXlsxExport(array $filters, \Illuminate\Database\Eloquent\Collection $rows): BinaryFileResponse|JsonResponse
    {
        if (!class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        $totalAktif = $rows->where('status', 'AKTIF')->sum('jumlah');

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pendapatan di Muka');

        $lastCol = 'H';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'LAPORAN PENDAPATAN DI MUKA');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $summaryText = sprintf(
            'Total PDM Aktif: Rp %s  |  Jumlah Record: %d',
            number_format($totalAktif, 0, ',', '.'),
            $rows->count()
        );
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', $summaryText);
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FF1B5E20']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8F5E9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(20);

        $periodeText = 'Periode: '
            . ($filters['tanggal_dari'] ?? '-') . ' s/d ' . ($filters['tanggal_sampai'] ?? '-')
            . '   |   Diekspor: ' . now()->format('d-m-Y H:i');
        $sheet->mergeCells("A3:{$lastCol}3");
        $sheet->setCellValue('A3', $periodeText);
        $sheet->getStyle('A3')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF455A64']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8F5E9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(3)->setRowHeight(18);
        $sheet->getRowDimension(4)->setRowHeight(6);

        $cols = [
            'A' => ['No',                  5],
            'B' => ['Tanggal Pencatatan', 18],
            'C' => ['Klien',              28],
            'D' => ['Investor',           28],
            'E' => ['No Ref Sumber',      24],
            'F' => ['Jumlah PDM',         18],
            'G' => ['Status',             14],
            'H' => ['Keterangan',         30],
        ];

        foreach ($cols as $col => [$label, $width]) {
            $sheet->setCellValue("{$col}5", $label);
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle("A5:{$lastCol}5")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E7D32']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF1B5E20']]],
        ]);
        $sheet->getRowDimension(5)->setRowHeight(22);

        $rowNum = 6;
        foreach ($rows as $i => $pdm) {
            $bg = $rowNum % 2 === 0 ? 'FFF1F8E9' : 'FFFFFFFF';

            $sheet->getCell("A{$rowNum}")->setValueExplicit($i + 1, DataType::TYPE_NUMERIC);
            $sheet->getCell("B{$rowNum}")->setValueExplicit($pdm->tanggal_pencatatan?->format('Y-m-d') ?? '', DataType::TYPE_STRING);
            $sheet->getCell("C{$rowNum}")->setValueExplicit($pdm->klienAr?->nama_klien ?? '', DataType::TYPE_STRING);
            $sheet->getCell("D{$rowNum}")->setValueExplicit($pdm->investor?->nama_investor ?? '-', DataType::TYPE_STRING);
            $sheet->getCell("E{$rowNum}")->setValueExplicit($pdm->sumberPembayaran?->no_referensi ?? '', DataType::TYPE_STRING);
            $sheet->getCell("F{$rowNum}")->setValueExplicit((float) $pdm->jumlah, DataType::TYPE_NUMERIC);
            $sheet->getStyle("F{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getCell("G{$rowNum}")->setValueExplicit($pdm->status, DataType::TYPE_STRING);
            $sheet->getCell("H{$rowNum}")->setValueExplicit($pdm->keterangan ?? '', DataType::TYPE_STRING);

            $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFCFD8DC']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($rowNum)->setRowHeight(18);
            $rowNum++;
        }

        $sheet->mergeCells("A{$rowNum}:E{$rowNum}");
        $sheet->setCellValue("A{$rowNum}", 'TOTAL PDM AKTIF');
        $sheet->getCell("F{$rowNum}")->setValueExplicit((float) $totalAktif, DataType::TYPE_NUMERIC);
        $sheet->getStyle("F{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF1B5E20']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8F5E9']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF2E7D32']]],
        ]);
        $sheet->getRowDimension($rowNum)->setRowHeight(20);

        $sheet->freezePane('A6');

        $temp = tempnam(sys_get_temp_dir(), 'pdm_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($temp);

        return response()
            ->download($temp, 'pendapatan-di-muka-' . now()->format('Ymd') . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    // Index/export sebelumnya ADMIN/MANAGER/SUPERVISOR-only (PIC AR tidak bisa
    // lihat/"Gunakan" PDM kliennya sendiri sama sekali). Kini dibuka untuk role
    // AR juga, tapi di-scope ke klien milik PIC AR yang login — mirror pola
    // ArFilterScope/RiwayatPembayaranService. ADMIN/MANAGER/SUPERVISOR tetap
    // melihat semua klien (tidak di-scope).
    private function applyPicArScope(array &$filters, Request $request): void
    {
        $user = $request->user()->loadMissing('karyawan');

        $karyawanId = RoleHelper::picArKaryawanIdFor($user);
        if ($karyawanId !== null) {
            $filters['pic_ar_karyawan_id'] = $karyawanId;
        }
    }

    // Sebelumnya store/cancel/gunakan sama sekali tidak mengecek kepemilikan PIC
    // AR — mirror authorizeKlienArOwnership() di BankStatementController supaya
    // PIC AR tidak bisa memproses PDM klien lain lewat endpoint ini.
    private function authorizeKlienArOwnership(?KlienAr $klienAr): void
    {
        $karyawanId = RoleHelper::picArKaryawanIdFor(auth()->user());
        if ($karyawanId === null) {
            return; // Admin/Manager/Supervisor: tidak dibatasi
        }

        abort_unless(
            $klienAr && (int) $klienAr->karyawan_ar_id === $karyawanId,
            403,
            'Anda tidak memiliki akses untuk memproses Pendapatan di Muka klien ini.'
        );
    }

    private function formatPdm(PendapatanDiMuka $pdm): array
    {
        $pdm->loadMissing(['klienAr', 'investor', 'sumberPembayaran', 'createdBy']);

        return [
            'id'                  => $pdm->id,
            'tanggal_pencatatan'  => $pdm->tanggal_pencatatan?->toDateString(),
            'klien'               => $pdm->klienAr?->nama_klien,
            'investor'            => $pdm->investor?->nama_investor,
            'no_referensi_sumber' => $pdm->sumberPembayaran?->no_referensi,
            'jumlah'              => (float) $pdm->jumlah,
            'status'              => $pdm->status,
            'keterangan'          => $pdm->keterangan,
            'created_by'          => $pdm->createdBy?->name,
        ];
    }
}
