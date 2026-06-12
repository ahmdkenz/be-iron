<?php

namespace App\Domain\Finance\Invoice\Jobs;

use App\Models\Invoice;
use App\Support\Helpers\GoogleDriveService;
use App\Support\Helpers\SignatureBarcodeHelper;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class UploadInvoiceToGDriveJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(private readonly int $invoiceId) {}

    public function handle(GoogleDriveService $driveService): void
    {
        $invoice = Invoice::with([
            'klienAr.karyawanAr',
            'perusahaan',
            'items',
            'openingBalanceDetails.items',
            'pembayarans',
            'createdBy.karyawan',
            'submittedBy.karyawan',
            'approvedBy.karyawan',
        ])->find($this->invoiceId);

        if (!$invoice) {
            Log::warning('UploadInvoiceToGDriveJob: invoice tidak ditemukan', ['id' => $this->invoiceId]);
            return;
        }

        // Opening balance hanya diupload setelah diapprove
        if ($invoice->requiresApproval() && !$invoice->isApprovedForFinanceFlow()) {
            Log::warning('UploadInvoiceToGDriveJob: opening balance belum approved, skip upload', [
                'invoice_id' => $invoice->id,
            ]);
            return;
        }

        try {
            $pdfContent = $this->generatePdf($invoice);
            $fileName   = 'Invoice-' . str_replace(['/', '\\', ' '], '-', $invoice->no_invoice) . '.pdf';
            $rootId     = config('services.google_drive.root_folder_id');

            $segment      = str_contains($invoice->no_invoice, 'B2C') ? 'B2C' : 'B2B';
            $typeFolderId = $driveService->findOrCreateInvoiceTypeFolder($rootId, $segment);

            $clientName = $invoice->klienAr->nama_klien;
            $folderId   = $driveService->findOrCreateClientFolder($typeFolderId, $clientName);

            // Jika invoice sudah punya file di Drive, UPDATE isi file (tidak buat file baru).
            // Ini mencegah duplikat ketika job di-dispatch lebih dari sekali untuk invoice yang sama
            // (misal: sekali dari import loop, sekali lagi dari cascadeCarryoverToNext).
            if ($invoice->gdrive_file_id) {
                $driveService->updatePdf($invoice->gdrive_file_id, $pdfContent);
                $fileId = $invoice->gdrive_file_id;
            } else {
                $fileId = $driveService->uploadPdf($folderId, $fileName, $pdfContent);
                $driveService->makeFilePublic($fileId);
            }

            // updateQuietly agar tidak trigger event audit blameable
            $invoice->updateQuietly([
                'gdrive_file_id'   => $fileId,
                'gdrive_folder_id' => $folderId,
            ]);

            Log::info('UploadInvoiceToGDriveJob: upload berhasil', [
                'invoice_id' => $invoice->id,
                'no_invoice' => $invoice->no_invoice,
                'file_id'    => $fileId,
                'folder_id'  => $folderId,
            ]);
        } catch (Throwable $e) {
            Log::error('UploadInvoiceToGDriveJob: upload gagal', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);

            throw $e; // Re-throw agar Laravel retry job sesuai $tries
        }
    }

    private function generatePdf(Invoice $invoice): string
    {
        $regularInvoicesInPeriod = collect();
        if ($invoice->is_opening_balance && $invoice->klien_ar_id && $invoice->tanggal_invoice) {
            $bulanAwal  = Carbon::parse($invoice->tanggal_invoice)->startOfMonth();
            $bulanAkhir = Carbon::parse($invoice->tanggal_invoice)->endOfMonth();
            $regularInvoicesInPeriod = Invoice::query()
                ->where('klien_ar_id', $invoice->klien_ar_id)
                ->where('perusahaan_id', $invoice->perusahaan_id)
                ->where('is_opening_balance', false)
                ->whereBetween('tanggal_invoice', [$bulanAwal->toDateString(), $bulanAkhir->toDateString()])
                ->orderBy('tanggal_invoice', 'asc')
                ->get();

            if ($regularInvoicesInPeriod->isNotEmpty()) {
                $regularInvoicesInPeriod->load([
                    'klienAr.karyawanAr',
                    'perusahaan',
                    'items',
                    'pembayarans',
                    'createdBy.karyawan',
                    'submittedBy.karyawan',
                    'approvedBy.karyawan',
                ]);
            }
        }

        $signatureData = $this->buildSignatureData($invoice);
        $regularInvoicesSignatureData = $regularInvoicesInPeriod
            ->mapWithKeys(fn ($inv) => [$inv->id => $this->buildSignatureData($inv)])
            ->all();

        return Pdf::loadView('finance.invoice-print', compact(
            'invoice', 'signatureData', 'regularInvoicesInPeriod', 'regularInvoicesSignatureData'
        ))
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => false,
                'defaultFont'          => 'Arial',
                'dpi'                  => 150,
            ])
            ->output();
    }

    private function buildSignatureData(Invoice $invoice): array
    {
        if ($invoice->is_opening_balance) {
            $preparedByUser = $invoice->submittedBy ?: $invoice->createdBy;
            $preparedByName = $preparedByUser?->karyawan?->nama_karyawan
                ?? $preparedByUser?->username
                ?? '___________________';

            $approvedByName = $invoice->approvedBy?->karyawan?->nama_karyawan
                ?? $invoice->approvedBy?->username
                ?? '___________________';
        } else {
            $preparedByName = $invoice->klienAr?->karyawanAr?->nama_karyawan ?? '___________________';
            $approvedByName = 'Direktur';
        }

        return [
            'prepared_by_name' => $preparedByName,
            'prepared_qr_src'  => SignatureBarcodeHelper::generateDataUri(
                SignatureBarcodeHelper::buildPreparedVerificationUrl($invoice), 150
            ),
            'approved_by_name' => $approvedByName,
            'approved_qr_src'  => SignatureBarcodeHelper::generateDataUri(
                SignatureBarcodeHelper::buildApprovedVerificationUrl($invoice), 150
            ),
        ];
    }
}
