<?php

namespace App\Domain\Master\Investor\Jobs;

use App\Domain\Master\Investor\Services\InvestorImportService;
use App\Models\InvestorImportBatch;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportInvestorJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /**
     * Import TIDAK boleh diulang otomatis: chunk yang sudah ter-commit
     * akan terduplikasi bila job dijalankan ulang.
     */
    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(private readonly string $batchId) {}

    public function handle(InvestorImportService $service): void
    {
        $batch = InvestorImportBatch::find($this->batchId);
        if (!$batch) {
            Log::warning('ImportInvestorJob: batch tidak ditemukan', ['id' => $this->batchId]);
            return;
        }

        $user = User::find($batch->user_id);
        if (!$user) {
            $batch->update(['status' => 'failed', 'message' => 'User pembuat import tidak valid.']);
            return;
        }

        // Set authenticated user agar auth()->id() & BlameableTrait berfungsi
        // sama seperti pada request sinkron sebelumnya.
        Auth::setUser($user);

        try {
            $service->process($batch);
        } catch (Throwable $e) {
            Log::error('ImportInvestorJob: import gagal', ['batch_id' => $batch->id, 'error' => $e->getMessage()]);
            $batch->update([
                'status'  => 'failed',
                'message' => 'Terjadi kesalahan sistem saat proses import: ' . $e->getMessage(),
            ]);
        } finally {
            $this->cleanupFile($batch->fresh());
        }
    }

    public function failed(Throwable $e): void
    {
        $batch = InvestorImportBatch::find($this->batchId);
        if ($batch && $batch->status !== 'completed') {
            $batch->update([
                'status'  => 'failed',
                'message' => 'Job import gagal: ' . $e->getMessage(),
            ]);
            $this->cleanupFile($batch);
        }
    }

    private function cleanupFile(?InvestorImportBatch $batch): void
    {
        if ($batch && $batch->file_path && Storage::disk('local')->exists($batch->file_path)) {
            Storage::disk('local')->delete($batch->file_path);
        }
    }
}
