<?php

namespace App\Console\Commands;

use App\Models\ImportMasterBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupImportMasterBatchesCommand extends Command
{
    protected $signature = 'master-data:cleanup-stale-imports';

    protected $description = 'Tandai batch import Master Data yang stale (worker mati) sebagai gagal. '
        . 'Dijalankan terjadwal, bukan per-request, supaya tidak mengunci baris batch yang sedang aktif '
        . 'diproses queue worker (pola sama dengan deadlock SQLSTATE[40001] yang diperbaiki di Bank Statement).';

    public function handle(): int
    {
        $staleCount = ImportMasterBatch::failStale();

        if ($staleCount > 0) {
            Log::info('master-data:cleanup-stale-imports', ['stale_failed' => $staleCount]);
        }

        $this->info("Selesai: {$staleCount} batch import master data ditandai gagal (stale).");

        return self::SUCCESS;
    }
}
