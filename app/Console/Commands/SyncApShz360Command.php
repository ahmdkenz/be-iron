<?php

namespace App\Console\Commands;

use App\Domain\Finance\ApShz360Sync\Services\ApShz360SyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncApShz360Command extends Command
{
    protected $signature = 'ap:sync-shz360-po';

    protected $description = 'Tarik PO & Terima PO (approved_direktur) dari SHZ360 untuk staging AP';

    public function handle(ApShz360SyncService $service): int
    {
        $run = $service->runFullSync();

        if ($run->status === 'failed') {
            Log::error('ap:sync-shz360-po gagal', ['run_id' => $run->id, 'error' => $run->message]);
            $this->error('Sync gagal: ' . $run->message);

            return self::FAILURE;
        }

        $this->info("Sync selesai. PO: {$run->po_upserted}/{$run->po_fetched}, Terima PO: {$run->receipt_upserted}/{$run->receipt_fetched}.");

        return self::SUCCESS;
    }
}
