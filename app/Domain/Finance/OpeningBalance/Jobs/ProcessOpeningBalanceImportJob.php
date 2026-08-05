<?php

namespace App\Domain\Finance\OpeningBalance\Jobs;

use App\Domain\Finance\OpeningBalance\Services\OpeningBalanceImportService;
use App\Domain\Notification\Services\FinanceNotificationService;
use App\Models\OpeningBalanceImportBatch;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessOpeningBalanceImportJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Import TIDAK boleh diulang otomatis: baris yang sudah ter-insert akan
     * terduplikasi bila job dijalankan ulang (lihat komentar sama di ImportMasterJob).
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

    public function __construct(private readonly string $batchId) {}

    public function handle(OpeningBalanceImportService $service, FinanceNotificationService $notifications): void
    {
        $batch = OpeningBalanceImportBatch::find($this->batchId);
        if (! $batch) {
            Log::warning('ProcessOpeningBalanceImportJob: batch tidak ditemukan', ['id' => $this->batchId]);

            return;
        }

        $user = User::find($batch->user_id);
        if (! $user) {
            $batch->update(['status' => 'failed', 'message' => 'User pembuat import tidak valid.']);

            return;
        }

        // Job berjalan tanpa HTTP session — InvoiceService::createOpeningBalance() bergantung
        // penuh pada auth()->id()/auth()->user()->karyawan untuk submitted_by/created_by & resolusi PIC.
        Auth::setUser($user);

        try {
            $service->process($batch);
        } catch (\PhpOffice\PhpSpreadsheet\Calculation\Exception $e) {
            Log::error('ProcessOpeningBalanceImportJob: rumus Excel gagal dihitung', ['batch_id' => $batch->id, 'error' => $e->getMessage()]);
            $batch->update([
                'status' => 'failed',
                'message' => self::FORMULA_CALCULATION_ERROR_MESSAGE,
            ]);
        } catch (Throwable $e) {
            Log::error('ProcessOpeningBalanceImportJob: import gagal', ['batch_id' => $batch->id, 'error' => $e->getMessage()]);
            $batch->update([
                'status' => 'failed',
                'message' => 'Terjadi kesalahan sistem saat proses import: '.$e->getMessage(),
            ]);
        } finally {
            $final = $batch->fresh();
            $this->cleanupFile($final);
            $this->notifyOutcome($notifications, $final);
        }
    }

    public function failed(Throwable $e): void
    {
        $batch = OpeningBalanceImportBatch::find($this->batchId);
        if ($batch && $batch->status !== 'completed') {
            $batch->update([
                'status' => 'failed',
                'message' => 'Job import gagal: '.$e->getMessage(),
            ]);
            $this->cleanupFile($batch);
            app(FinanceNotificationService::class)->importFailed('opening_balance', $batch->user_id, 'Import Master Opening Balance', $batch->message ?? 'Import gagal.');
        }
    }

    private function notifyOutcome(FinanceNotificationService $notifications, ?OpeningBalanceImportBatch $batch): void
    {
        if (! $batch) {
            return;
        }

        if ($batch->status === 'completed') {
            $notifications->importCompleted('opening_balance', $batch->user_id, 'Import Master Opening Balance', $batch->message ?? 'Import Opening Balance selesai.');

            // Notifikasi agregat ke approver — createOpeningBalance() dipanggil dengan
            // notify: false per baris (lihat OpeningBalanceImportService) supaya approver
            // tidak dibanjiri satu notifikasi per baris untuk satu batch import.
            if ($batch->inserted_ob > 0) {
                $notifications->obArBulkSubmitted($batch->inserted_ob, $batch->user_id);
            }
        } elseif ($batch->status === 'failed') {
            $notifications->importFailed('opening_balance', $batch->user_id, 'Import Master Opening Balance', $batch->message ?? 'Import Opening Balance gagal.');
        }
    }

    private function cleanupFile(?OpeningBalanceImportBatch $batch): void
    {
        if ($batch && $batch->file_path && Storage::disk('local')->exists($batch->file_path)) {
            Storage::disk('local')->delete($batch->file_path);
        }
    }
}
