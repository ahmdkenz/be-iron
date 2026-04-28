<?php

namespace App\Domain\Finance\Invoice\Controllers;

use App\Domain\Finance\Invoice\DTO\InvoiceDTO;
use App\Domain\Finance\Invoice\Requests\StoreInvoiceRequest;
use App\Domain\Finance\Invoice\Requests\UpdateInvoiceRequest;
use App\Domain\Finance\Invoice\Resources\InvoiceResource;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Http\Controllers\Controller;
use App\Models\KlienAr;
use App\Support\Helpers\RoleHelper;
use App\Support\Helpers\SignatureBarcodeHelper;
use App\Support\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
            'No Invoice', 'Klien', 'Perusahaan', 'Tanggal Invoice', 'Jatuh Tempo',
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
                    $inv->tanggal_jatuh_tempo?->format('Y-m-d'),
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
            'items',
            'pembayarans',
            'createdBy.karyawan',
            'submittedBy.karyawan',
            'approvedBy.karyawan',
        ]);

        $signatureData = $this->buildSignatureData($invoice);

        if ($request->has('html')) {
            return view('finance.invoice-print', compact('invoice', 'signatureData'))->render();
        }

        $filename = 'Invoice-' . str_replace(['/', '\\', ' '], '-', $invoice->no_invoice) . '.pdf';

        return Pdf::loadView('finance.invoice-print', compact('invoice', 'signatureData'))
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => false,
                'defaultFont'          => 'Arial',
                'dpi'                  => 150,
            ])
            ->stream($filename);
    }

    private function buildSignatureData($invoice): array
    {
        if (!$invoice->is_opening_balance) {
            return [
                'prepared_by_name' => $invoice->klienAr?->karyawanAr?->nama_karyawan ?? '___________________',
                'prepared_barcode_src' => null,
                'approved_by_name' => '___________________',
                'approved_barcode_src' => null,
            ];
        }

        $preparedByUser = $invoice->submittedBy ?: $invoice->createdBy;
        $preparedByName = $preparedByUser?->karyawan?->nama_karyawan
            ?? $preparedByUser?->username
            ?? '___________________';

        $approvedByName = $invoice->approvedBy?->karyawan?->nama_karyawan
            ?? $invoice->approvedBy?->username
            ?? '___________________';

        return [
            'prepared_by_name' => $preparedByName,
            'prepared_barcode_src' => $invoice->is_opening_balance
                ? SignatureBarcodeHelper::generateDataUri(
                    SignatureBarcodeHelper::buildPreparedOpeningBalancePayload($invoice)
                )
                : null,
            'approved_by_name' => $approvedByName,
            'approved_barcode_src' => $invoice->is_opening_balance
                ? SignatureBarcodeHelper::generateDataUri(
                    SignatureBarcodeHelper::buildApprovedOpeningBalancePayload($invoice)
                )
                : null,
        ];
    }
}
