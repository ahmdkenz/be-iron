<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Jobs;

use App\Domain\Finance\RekonsiliasiBankStatement\Services\BankStatementImportService;
use App\Domain\Notification\Services\FinanceNotificationService;
use App\Models\BankStatementImportBatch;
use App\Models\BankStatementImportRow;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportBankStatementJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /**
     * Import TIDAK boleh diulang otomatis: baris staging yang sudah ter-insert
     * akan terduplikasi bila job dijalankan ulang begitu saja oleh queue worker.
     */
    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(private readonly string $batchId)
    {
        $this->onQueue('bank-statement');
    }

    public function handle(BankStatementImportService $service, FinanceNotificationService $notifications): void
    {
        $batch = BankStatementImportBatch::find($this->batchId);
        if (!$batch) {
            Log::warning('ImportBankStatementJob: batch tidak ditemukan', ['id' => $this->batchId]);
            return;
        }

        $user = User::find($batch->user_id);
        if (!$user) {
            $batch->update(['status' => 'failed', 'phase' => 'failed', 'message' => 'User pembuat import tidak valid.', 'finished_at' => now()]);
            return;
        }

        Auth::setUser($user);

        try {
            $service->run($batch);
        } catch (Throwable $e) {
            Log::error('ImportBankStatementJob: import gagal', ['batch_id' => $batch->id, 'error' => $e->getMessage()]);
            $batch->update([
                'status'      => 'failed',
                'phase'       => 'failed',
                'message'     => 'Terjadi kesalahan sistem saat proses import: ' . $e->getMessage(),
                'finished_at' => now(),
            ]);
        } finally {
            $final = $batch->fresh();
            $this->cleanup($final);
            $this->notifyOutcome($notifications, $final);
        }
    }

    public function failed(Throwable $e): void
    {
        $batch = BankStatementImportBatch::find($this->batchId);
        if ($batch && !in_array($batch->status, ['completed', 'needs_confirmation'], true)) {
            $batch->update([
                'status'      => 'failed',
                'phase'       => 'failed',
                'message'     => 'Job import gagal: ' . $e->getMessage(),
                'finished_at' => now(),
            ]);
            $this->cleanup($batch);
            app(FinanceNotificationService::class)->importFailed('bank', $batch->user_id, 'Import Rekening Koran', $batch->message ?? 'Import gagal.');
        }
    }

    private function notifyOutcome(FinanceNotificationService $notifications, ?BankStatementImportBatch $batch): void
    {
        if (!$batch) {
            return;
        }

        match ($batch->status) {
            'completed'           => $notifications->importCompleted('bank', $batch->user_id, 'Import Rekening Koran', $batch->message ?? 'Import rekening koran selesai.'),
            'failed'              => $notifications->importFailed('bank', $batch->user_id, 'Import Rekening Koran', $batch->message ?? 'Import rekening koran gagal.'),
            'needs_confirmation'  => $notifications->bankImportNeedsConfirmation($batch->user_id, $batch->message ?? 'Periode rekening koran bertumpang tindih dengan data yang sudah ada.'),
            default               => null,
        };
    }

    /**
     * File upload & baris staging hanya dibersihkan pada status terminal
     * (completed/failed) — needs_confirmation masih membutuhkan keduanya untuk
     * dipakai ulang saat job didispatch kembali lewat endpoint confirm-replace.
     */
    private function cleanup(?BankStatementImportBatch $batch): void
    {
        if (!$batch || !in_array($batch->status, ['completed', 'failed'], true)) {
            return;
        }

        if ($batch->file_path && Storage::disk('local')->exists($batch->file_path)) {
            Storage::disk('local')->delete($batch->file_path);
        }

        BankStatementImportRow::where('batch_id', $batch->id)->delete();
    }
}
