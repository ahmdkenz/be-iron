<?php

namespace App\Domain\Finance\Invoice\Jobs;

use App\Domain\Finance\Invoice\Services\InvoiceImportService;
use App\Models\InvoiceImportBatch;
use App\Models\User;
use App\Support\Jobs\Middleware\LogsImportQueryStats;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fase 2: bandingkan tiap grup dengan invoice existing dan tentukan
 * NEW_INVOICE / UNCHANGED / SAFE_UPDATE / REVIEW_REQUIRED / REJECTED.
 *
 * Selesai job ini batch berhenti di status awaiting_review — menunggu user
 * menekan "Proses Data Aman". Masih belum ada invoice yang ditulis.
 */
class ClassifyInvoiceImportJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(private readonly string $batchId)
    {
        $this->onQueue('invoice-import');
    }

    public function middleware(): array
    {
        return [new LogsImportQueryStats()];
    }

    public function handle(InvoiceImportService $service): void
    {
        $batch = InvoiceImportBatch::find($this->batchId);
        if (!$batch) {
            Log::warning('ClassifyInvoiceImportJob: batch tidak ditemukan', ['id' => $this->batchId]);

            return;
        }

        $user = User::find($batch->user_id);
        if (!$user) {
            $this->markFailed($batch, 'User pembuat import tidak valid.');

            return;
        }

        Auth::setUser($user);

        try {
            $service->classify($batch);
        } catch (Throwable $e) {
            Log::error('ClassifyInvoiceImportJob: klasifikasi gagal', ['batch_id' => $batch->id, 'error' => $e->getMessage()]);
            $this->markFailed($batch, 'Gagal mengklasifikasi data: ' . $e->getMessage());
        }
    }

    public function failed(Throwable $e): void
    {
        $batch = InvoiceImportBatch::find($this->batchId);
        if ($batch && !$batch->isTerminal()) {
            $this->markFailed($batch, 'Job klasifikasi gagal: ' . $e->getMessage());
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
