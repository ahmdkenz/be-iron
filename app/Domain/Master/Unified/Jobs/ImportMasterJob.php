<?php

namespace App\Domain\Master\Unified\Jobs;

use App\Domain\Master\Unified\Services\MasterImportService;
use App\Models\ImportMasterBatch;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportMasterJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /**
     * Import TIDAK boleh diulang otomatis: chunk yang sudah ter-commit
     * akan terduplikasi bila job dijalankan ulang.
     */
    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(private readonly string $batchId) {}

    public function handle(MasterImportService $service): void
    {
        $batch = ImportMasterBatch::find($this->batchId);
        if (!$batch) {
            Log::warning('ImportMasterJob: batch tidak ditemukan', ['id' => $this->batchId]);
            return;
        }

        $user = User::find($batch->user_id);
        if (!$user) {
            $batch->update(['status' => 'failed', 'message' => 'User pembuat import tidak valid.']);
            return;
        }

        Auth::setUser($user);

        try {
            $service->process($batch);
        } catch (Throwable $e) {
            Log::error('ImportMasterJob: import gagal', ['batch_id' => $batch->id, 'error' => $e->getMessage()]);
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
        $batch = ImportMasterBatch::find($this->batchId);
        if ($batch && $batch->status !== 'completed') {
            $batch->update([
                'status'  => 'failed',
                'message' => 'Job import gagal: ' . $e->getMessage(),
            ]);
            $this->cleanupFile($batch);
        }
    }

    private function cleanupFile(?ImportMasterBatch $batch): void
    {
        if ($batch && $batch->file_path && Storage::disk('local')->exists($batch->file_path)) {
            Storage::disk('local')->delete($batch->file_path);
        }
    }
}
