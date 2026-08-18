<?php

namespace App\Console\Commands;

use App\Models\OpeningBalanceImportBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupOpeningBalanceImportBatchesCommand extends Command
{
    protected $signature = 'opening-balance:cleanup-stale-imports';

    protected $description = 'Tandai batch import Opening Balance AR yang stale (worker mati) atau '
        . 'menunggu konfirmasi (needs_confirmation) yang ditinggalkan. Dijalankan terjadwal, mirror '
        . 'bank-statement:cleanup-stale-imports.';

    public function handle(): int
    {
        $staleCount     = OpeningBalanceImportBatch::failStale();
        $abandonedCount = OpeningBalanceImportBatch::cancelAbandonedConfirmations();

        if ($staleCount > 0 || $abandonedCount > 0) {
            Log::info('opening-balance:cleanup-stale-imports', [
                'stale_failed'       => $staleCount,
                'abandoned_canceled' => $abandonedCount,
            ]);
        }

        $this->info("Selesai: {$staleCount} batch ditandai gagal (stale), {$abandonedCount} batch needs_confirmation dibatalkan (abandoned).");

        return self::SUCCESS;
    }
}
