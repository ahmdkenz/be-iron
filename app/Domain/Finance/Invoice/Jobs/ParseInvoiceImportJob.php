<?php

namespace App\Domain\Finance\Invoice\Jobs;

use App\Domain\Finance\Invoice\Services\InvoiceImportService;
use App\Models\InvoiceImportBatch;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fase 1: baca file Excel → tb_invoice_import_groups / _rows.
 * Tidak menulis apa pun ke tb_invoice.
 */
class ParseInvoiceImportJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /**
     * Jangan diulang otomatis: grup/baris staging yang sudah ter-insert akan
     * terduplikasi bila job dijalankan ulang begitu saja oleh queue worker.
     */
    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(private readonly string $batchId)
    {
        $this->onQueue('invoice-import');
    }

    public function handle(InvoiceImportService $service): void
    {
        $batch = InvoiceImportBatch::find($this->batchId);
        if (!$batch) {
            Log::warning('ParseInvoiceImportJob: batch tidak ditemukan', ['id' => $this->batchId]);

            return;
        }

        $user = User::find($batch->user_id);
        if (!$user) {
            $this->markFailed($batch, 'User pembuat import tidak valid.');

            return;
        }

        Auth::setUser($user);

        try {
            $service->parse($batch);
        } catch (Throwable $e) {
            Log::error('ParseInvoiceImportJob: parsing gagal', ['batch_id' => $batch->id, 'error' => $e->getMessage()]);
            $this->markFailed($batch, $e->getMessage());

            return;
        }

        ClassifyInvoiceImportJob::dispatch($batch->id);
    }

    public function failed(Throwable $e): void
    {
        $batch = InvoiceImportBatch::find($this->batchId);
        if ($batch && !$batch->isTerminal()) {
            $this->markFailed($batch, 'Job parsing gagal: ' . $e->getMessage());
        }
    }

    private function markFailed(InvoiceImportBatch $batch, string $message): void
    {
        $batch->update([
            'status'      => 'failed',
            'phase'       => 'failed',
            'message'     => $message,
            'finished_at' => now(),
        ]);
    }
}
