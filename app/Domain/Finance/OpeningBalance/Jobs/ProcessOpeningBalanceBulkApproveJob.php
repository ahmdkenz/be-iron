<?php

namespace App\Domain\Finance\OpeningBalance\Jobs;

use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\OpeningBalance\Services\OpeningBalanceBulkApproveCacheService;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessOpeningBalanceBulkApproveJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        private readonly string $batchId,
        private readonly array $ids,
        private readonly ?string $note,
        private readonly int $userId,
    ) {}

    public function handle(InvoiceService $service, OpeningBalanceBulkApproveCacheService $cache): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            $cache->update($this->batchId, ['status' => 'failed', 'message' => 'User yang menyetujui tidak valid.']);
            $cache->clearActive($this->userId, $this->batchId);

            return;
        }

        // Job berjalan tanpa HTTP session — InvoiceService::approveOpeningBalance()
        // bergantung penuh pada auth()->id() untuk approved_by/updated_by & actor log.
        Auth::setUser($user);

        $cache->update($this->batchId, ['status' => 'processing']);

        $approved = 0;
        $failed = [];

        try {
            foreach ($this->ids as $index => $id) {
                try {
                    $invoice = $service->resolveOpeningBalanceForActor((int) $id);
                    $service->approveOpeningBalance($invoice, $this->note);
                    $approved++;
                } catch (Throwable $e) {
                    $failed[] = ['id' => $id, 'message' => $e->getMessage()];
                }

                $cache->update($this->batchId, [
                    'processed' => $index + 1,
                    'approved' => $approved,
                    'failed' => $failed,
                ]);
            }

            $cache->update($this->batchId, ['status' => 'completed']);

            Log::channel('security')->info('Bulk approve opening balance', [
                'user_id' => $this->userId,
                'batch_id' => $this->batchId,
                'approved' => $approved,
                'failed' => $failed,
            ]);
        } catch (Throwable $e) {
            Log::error('ProcessOpeningBalanceBulkApproveJob: gagal', ['batch_id' => $this->batchId, 'error' => $e->getMessage()]);
            $cache->update($this->batchId, [
                'status' => 'failed',
                'message' => 'Terjadi kesalahan sistem saat proses approve semua: '.$e->getMessage(),
            ]);
        } finally {
            $cache->clearActive($this->userId, $this->batchId);
        }
    }

    public function failed(Throwable $e): void
    {
        $cache = app(OpeningBalanceBulkApproveCacheService::class);
        $cache->update($this->batchId, [
            'status' => 'failed',
            'message' => 'Job approve semua gagal: '.$e->getMessage(),
        ]);
        $cache->clearActive($this->userId, $this->batchId);
    }
}
