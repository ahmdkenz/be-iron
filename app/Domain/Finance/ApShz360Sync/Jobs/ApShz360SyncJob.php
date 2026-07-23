<?php

namespace App\Domain\Finance\ApShz360Sync\Jobs;

use App\Domain\Finance\ApShz360Sync\Exceptions\ApShz360SyncInProgressException;
use App\Domain\Finance\ApShz360Sync\Services\ApShz360SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ApShz360SyncJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    // Idempotent by design (source_hash + updateOrCreate), tapi tidak perlu retry
    // otomatis — kegagalan normal sudah ditangani & dicatat di dalam runFullSync().
    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(private readonly bool $forceFullResync = false) {}

    public function handle(ApShz360SyncService $service): void
    {
        try {
            $service->runFullSync($this->forceFullResync);
        } catch (ApShz360SyncInProgressException $e) {
            Log::warning('ApShz360SyncJob: sync lain (scheduler/job lain) sedang berjalan, dilewati.', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
