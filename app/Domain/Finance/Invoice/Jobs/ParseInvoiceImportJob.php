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
        } catch (\PhpOffice\PhpSpreadsheet\Calculation\Exception $e) {
            Log::error('ParseInvoiceImportJob: rumus Excel gagal dihitung', ['batch_id' => $batch->id, 'error' => $e->getMessage()]);
            $this->markFailed($batch, self::FORMULA_CALCULATION_ERROR_MESSAGE);

            return;
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
