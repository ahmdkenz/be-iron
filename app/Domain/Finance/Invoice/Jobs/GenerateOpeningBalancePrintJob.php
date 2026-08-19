<?php

namespace App\Domain\Finance\Invoice\Jobs;

use App\Domain\Finance\Invoice\Services\InvoicePrintCacheService;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generate PDF cetak Opening Balance di background — dipindah dari request HTTP
 * sinkron karena bisa berisi puluhan-ratusan halaman replika invoice reguler
 * (lihat catatan performa cetak Opening Balance). Hasilnya di-cache di disk oleh
 * InvoicePrintCacheService supaya generate ulang tidak terjadi setiap klik Cetak.
 */
class GenerateOpeningBalancePrintJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        private readonly int $invoiceId,
        private readonly string $version,
    ) {
        $this->onQueue('invoice-print');
    }

    public function handle(InvoiceService $invoiceService, InvoicePrintCacheService $printCache): void
    {
        try {
            $invoice = $invoiceService->findForPrintOrFail($this->invoiceId);

            [$regularInvoicesInPeriod, $regularInvoicesSignatureData] = $invoiceService
                ->buildOpeningBalanceRegularInvoicesPrintData($invoice);

            $invoiceService->attachPrintItems($invoice);
            $signatureData = $invoiceService->buildSignatureData($invoice);

            // compact() tidak bisa dipakai di sini: fn() hanya meng-capture variabel
            // yang muncul sebagai token $nama secara tekstual di badannya, sedangkan
            // compact('invoice', ...) merujuk nama lewat string sehingga $invoice dkk.
            // tidak ikut ter-capture (jadi undefined di dalam closure).
            $viewData = [
                'invoice'                      => $invoice,
                'signatureData'                => $signatureData,
                'regularInvoicesInPeriod'      => $regularInvoicesInPeriod,
                'regularInvoicesSignatureData' => $regularInvoicesSignatureData,
            ];

            $pdfBinary = $printCache->withBoostedRenderLimits(
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

            $printCache->store($this->invoiceId, $this->version, $pdfBinary);
        } catch (Throwable $e) {
            Log::error('GenerateOpeningBalancePrintJob gagal', [
                'invoice_id' => $this->invoiceId,
                'version'    => $this->version,
                'message'    => $e->getMessage(),
            ]);

            $printCache->markFailed(
                $this->invoiceId,
                $this->version,
                'Gagal membuat dokumen cetak. Silakan coba lagi.'
            );
        } finally {
            $printCache->clearDispatched($this->invoiceId, $this->version);
        }
    }

    /**
     * Jaring pengaman kalau handle() tidak sempat sampai ke finally-nya sendiri
     * (mis. proses worker mati total karena fatal error PHP di luar try/catch biasa,
     * atau job dianggap gagal permanen oleh queue setelah tries habis) — tanpa ini,
     * penanda "sedang diproses" bisa nyangkut sampai TTL-nya habis dan status cetak
     * di FE terlihat processing terus tanpa pernah pindah ke failed.
     */
    public function failed(?Throwable $exception): void
    {
        $printCache = app(InvoicePrintCacheService::class);

        $printCache->markFailed(
            $this->invoiceId,
            $this->version,
            'Gagal membuat dokumen cetak. Silakan coba lagi.'
        );
        $printCache->clearDispatched($this->invoiceId, $this->version);
    }
}
