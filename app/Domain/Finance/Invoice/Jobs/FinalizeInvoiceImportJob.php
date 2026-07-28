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
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fase 4: propagasi carryover untuk invoice yang baru dibuat, tutup batch,
 * lalu buang file upload (data hasil klasifikasi tetap tersimpan di DB supaya
 * tabel review masih bisa dibuka setelah proses selesai).
 */
class FinalizeInvoiceImportJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

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
            Log::warning('FinalizeInvoiceImportJob: batch tidak ditemukan', ['id' => $this->batchId]);

            return;
        }

        $user = User::find($batch->user_id);
        if ($user) {
            Auth::setUser($user);
        }

        try {
            $service->finalizeApply($batch);
        } catch (Throwable $e) {
            Log::error('FinalizeInvoiceImportJob: finalisasi gagal', ['batch_id' => $batch->id, 'error' => $e->getMessage()]);
            $batch->update([
                'status'      => 'failed',
                'phase'       => 'failed',
                'message'     => 'Gagal menutup batch import: ' . $e->getMessage(),
                'finished_at' => now(),
            ]);
        } finally {
            $this->cleanup($batch->fresh());
        }
    }

    private function cleanup(?InvoiceImportBatch $batch): void
    {
        if (!$batch?->file_path) {
            return;
        }

        try {
            Storage::disk('local')->delete($batch->file_path);
            $batch->update(['file_path' => null]);
        } catch (Throwable $e) {
            Log::warning('FinalizeInvoiceImportJob: gagal menghapus file import', ['batch_id' => $batch->id, 'error' => $e->getMessage()]);
        }
    }
}
