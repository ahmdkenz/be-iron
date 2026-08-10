<?php

namespace App\Domain\Master\Unified\Jobs;

use App\Domain\Master\Unified\Services\MasterImportService;
use App\Domain\Notification\Services\FinanceNotificationService;
use App\Models\ImportMasterBatch;
use App\Models\User;
use App\Support\Jobs\Middleware\LogsImportQueryStats;
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

    /**
     * Pesan ramah untuk PhpOffice\PhpSpreadsheet\Calculation\Exception (rumus gagal fatal,
     * mis. circular reference atau referensi ke sheet/file lain yang tidak ikut di-upload) —
     * menggantikan pesan teknis mentah PhpSpreadsheet yang tidak bisa ditindaklanjuti user awam.
     */
    private const FORMULA_CALCULATION_ERROR_MESSAGE = 'File Excel yang Anda upload berisi rumus yang tidak bisa '
        . 'dihitung oleh sistem. Penyebab paling umum: (1) ada rumus yang saling merujuk satu sama lain tanpa '
        . 'henti (disebut "circular reference"), atau (2) rumus mengambil data dari sheet/file Excel lain yang '
        . 'tidak ikut di-upload. Cara mengatasi: buka file Excel Anda, cari sel yang berisi rumus (biasanya '
        . 'diawali tanda "="), lalu ganti dengan nilai angka/teks biasa — bisa dengan cara salin sel tersebut '
        . '(Copy) lalu tempel sebagai nilai saja (Paste Special > Values). Setelah itu simpan file dan upload ulang.';

    public function __construct(private readonly string $batchId)
    {
        $this->onQueue('invoice-import');
    }

    public function middleware(): array
    {
        return [new LogsImportQueryStats()];
    }

    public function handle(MasterImportService $service, FinanceNotificationService $notifications): void
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
        } catch (\PhpOffice\PhpSpreadsheet\Calculation\Exception $e) {
            Log::error('ImportMasterJob: rumus Excel gagal dihitung', ['batch_id' => $batch->id, 'error' => $e->getMessage()]);
            $batch->update([
                'status'  => 'failed',
                'message' => self::FORMULA_CALCULATION_ERROR_MESSAGE,
            ]);
        } catch (Throwable $e) {
            Log::error('ImportMasterJob: import gagal', ['batch_id' => $batch->id, 'error' => $e->getMessage()]);
            $batch->update([
                'status'  => 'failed',
                'message' => 'Terjadi kesalahan sistem saat proses import: ' . $e->getMessage(),
            ]);
        } finally {
            $final = $batch->fresh();
            $this->cleanupFile($final);
            $this->notifyOutcome($notifications, $final);
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
            app(FinanceNotificationService::class)->importFailed('master', $batch->user_id, 'Import Master Data', $batch->message ?? 'Import gagal.');
        }
    }

    private function notifyOutcome(FinanceNotificationService $notifications, ?ImportMasterBatch $batch): void
    {
        if (!$batch) {
            return;
        }

        if ($batch->status === 'completed') {
            $notifications->importCompleted('master', $batch->user_id, 'Import Master Data', $batch->message ?? 'Import master data selesai.');
        } elseif ($batch->status === 'failed') {
            $notifications->importFailed('master', $batch->user_id, 'Import Master Data', $batch->message ?? 'Import master data gagal.');
        }
    }

    private function cleanupFile(?ImportMasterBatch $batch): void
    {
        if ($batch && $batch->file_path && Storage::disk('local')->exists($batch->file_path)) {
            Storage::disk('local')->delete($batch->file_path);
        }
    }
}
