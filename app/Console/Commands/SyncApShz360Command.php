<?php

namespace App\Console\Commands;

use App\Domain\Finance\ApShz360Sync\Exceptions\ApShz360SyncInProgressException;
use App\Domain\Finance\ApShz360Sync\Services\ApShz360SyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncApShz360Command extends Command
{
    protected $signature = 'ap:sync-shz360-po';

    protected $description = 'Tarik PO & Terima PO (approved_direktur) dari SHZ360 untuk staging AP';

    public function handle(ApShz360SyncService $service): int
    {
        try {
            $run = $service->runFullSync();
        } catch (ApShz360SyncInProgressException $e) {
            $this->warn($e->getMessage());

            return self::SUCCESS;
        }

        if ($run->status === 'failed') {
            Log::error('ap:sync-shz360-po gagal', ['run_id' => $run->id, 'error' => $run->message]);
            $this->error('Sync gagal: ' . $run->message);

            return self::FAILURE;
        }

        if ($run->status === 'partial_success') {
            Log::warning('ap:sync-shz360-po selesai sebagian', ['run_id' => $run->id, 'error_count' => $run->errors()->count()]);
        }

        $this->info("Sync selesai ({$run->status}). PO: {$run->po_upserted}/{$run->po_fetched}, Terima PO: {$run->receipt_upserted}/{$run->receipt_fetched}.");

        return self::SUCCESS;
    }
}
