<?php

namespace App\Console\Commands;

use App\Models\BankStatementImportBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupBankStatementImportBatchesCommand extends Command
{
    protected $signature = 'bank-statement:cleanup-stale-imports';

    protected $description = 'Tandai batch import rekening koran yang stale (worker mati) atau '
        . 'needs_confirmation yang ditinggalkan. Dijalankan terjadwal, bukan per-request, supaya '
        . 'tidak mengunci baris batch yang sedang aktif diproses queue worker (lihat deadlock '
        . 'SQLSTATE[40001] pada polling importStatus() sebelum fix ini).';

    public function handle(): int
    {
        $staleCount     = BankStatementImportBatch::failStale();
        $abandonedCount = BankStatementImportBatch::cancelAbandonedConfirmations();

        if ($staleCount > 0 || $abandonedCount > 0) {
            Log::info('bank-statement:cleanup-stale-imports', [
                'stale_failed'         => $staleCount,
                'abandoned_canceled'   => $abandonedCount,
            ]);
        }

        $this->info("Selesai: {$staleCount} batch ditandai gagal (stale), {$abandonedCount} batch needs_confirmation dibatalkan (abandoned).");

        return self::SUCCESS;
    }
}
