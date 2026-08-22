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
 * Fase 3: tulis invoice untuk grup NEW_INVOICE & SAFE_UPDATE, satu chunk per job,
 * sambil mencatat hasilnya ke tb_invoice_import_change_log.
 *
 * Grup REJECTED (invoice sudah LUNAS / periode terkunci di Ending Balance) dan
 * UNCHANGED tidak pernah disentuh di sini — itulah inti jaminan "reupload tidak
 * merusak invoice yang sudah selesai ditagih". Job men-dispatch dirinya sendiri
 * sampai antrean grup aman habis, lalu menyerahkan penutupan batch ke
 * FinalizeInvoiceImportJob.
 */
class ProcessInvoiceImportChunkJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /**
     * Sekali jalan saja: grup yang sudah ditandai apply_status tidak akan
     * diproses ulang, tapi chunk yang mati di tengah tidak boleh diulang otomatis
     * karena sebagian invoice-nya sudah tertulis.
     */
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
            Log::warning('ProcessInvoiceImportChunkJob: batch tidak ditemukan', ['id' => $this->batchId]);

            return;
        }

        $user = User::find($batch->user_id);
        if (!$user) {
            $this->markFailed($batch, 'User pembuat import tidak valid.');

            return;
        }

        Auth::setUser($user);

        try {
            $hasMore = $service->applySafeChunk($batch);
        } catch (Throwable $e) {
            Log::error('ProcessInvoiceImportChunkJob: proses gagal', ['batch_id' => $batch->id, 'error' => $e->getMessage()]);
            $this->markFailed($batch, 'Gagal memproses data aman: ' . $e->getMessage());

            return;
        }

        if ($hasMore) {
            self::dispatch($batch->id);

            return;
        }

        FinalizeInvoiceImportJob::dispatch($batch->id);
    }

    public function failed(Throwable $e): void
    {
        $batch = InvoiceImportBatch::find($this->batchId);
        if ($batch && !$batch->isTerminal()) {
            $this->markFailed($batch, 'Job proses data aman gagal: ' . $e->getMessage());
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
