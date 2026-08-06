<?php

namespace Tests\Unit;

use App\Domain\Finance\OpeningBalance\Services\OpeningBalanceBulkApproveCacheService;
use Tests\TestCase;

/**
 * Progres batch "Approve Semua" Opening Balance numpang di Laravel Cache
 * (bukan tabel DB baru — lihat InvoicePrintCacheService untuk pola yang
 * sama). Test murni terhadap Cache (driver `array` di phpunit.xml), tanpa
 * DB sama sekali.
 */
class OpeningBalanceBulkApproveCacheServiceTest extends TestCase
{
    private function service(): OpeningBalanceBulkApproveCacheService
    {
        return app(OpeningBalanceBulkApproveCacheService::class);
    }

    public function test_start_batch_membuat_progress_awal_dan_menandai_active_untuk_user(): void
    {
        $service = $this->service();
        $batchId = $service->startBatch(userId: 7, total: 3);

        $this->assertNotEmpty($batchId);
        $this->assertSame($batchId, $service->activeBatchId(7));

        $progress = $service->progress($batchId);
        $this->assertSame('queued', $progress['status']);
        $this->assertSame(3, $progress['total']);
        $this->assertSame(0, $progress['processed']);
        $this->assertSame(0, $progress['approved']);
        $this->assertSame([], $progress['failed']);
    }

    public function test_update_menggabungkan_partial_ke_progress_yang_sudah_ada(): void
    {
        $service = $this->service();
        $batchId = $service->startBatch(userId: 1, total: 2);

        $service->update($batchId, ['status' => 'processing', 'processed' => 1, 'approved' => 1]);
        $progress = $service->progress($batchId);

        $this->assertSame('processing', $progress['status']);
        $this->assertSame(1, $progress['processed']);
        $this->assertSame(1, $progress['approved']);
        // Field lain (mis. total) tidak boleh hilang setelah partial update.
        $this->assertSame(2, $progress['total']);
    }

    public function test_active_batch_id_null_untuk_user_tanpa_batch_berjalan(): void
    {
        $this->assertNull($this->service()->activeBatchId(999));
    }

    public function test_clear_active_hanya_melepas_lock_jika_masih_menunjuk_batch_yang_sama(): void
    {
        $service = $this->service();
        $firstBatch = $service->startBatch(userId: 5, total: 1);

        // User memicu batch baru (mis. setelah batch pertama selesai secara manual) —
        // clearActive dari batch LAMA tidak boleh menghapus lock batch BARU (race guard).
        $secondBatch = $service->startBatch(userId: 5, total: 1);
        $service->clearActive(5, $firstBatch);

        $this->assertSame($secondBatch, $service->activeBatchId(5));

        $service->clearActive(5, $secondBatch);
        $this->assertNull($service->activeBatchId(5));
    }

    public function test_progress_null_untuk_batch_yang_tidak_dikenal(): void
    {
        $this->assertNull($this->service()->progress('batch-tidak-ada'));
    }
}
