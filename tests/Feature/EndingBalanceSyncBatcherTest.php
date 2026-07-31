<?php

namespace Tests\Feature;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceService;
use App\Domain\Finance\EndingBalance\Services\EndingBalanceSyncBatcher;
use RuntimeException;
use Tests\TestCase;

/**
 * Menguji EndingBalanceSyncBatcher secara terisolasi (tanpa DB) — dedup key,
 * nesting, resiliency terhadap exception, dan isolasi kegagalan antar key.
 * State statis di-reset di setUp/tearDown karena bisa bocor antar test method
 * dalam satu proses PHPUnit.
 */
class EndingBalanceSyncBatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EndingBalanceSyncBatcher::resetForTesting();
    }

    protected function tearDown(): void
    {
        EndingBalanceSyncBatcher::resetForTesting();
        parent::tearDown();
    }

    private function bindMock(): \PHPUnit\Framework\MockObject\MockObject
    {
        $mock = $this->createMock(EndingBalanceService::class);
        $this->app->instance(EndingBalanceService::class, $mock);

        return $mock;
    }

    public function test_isActive_is_false_outside_run(): void
    {
        $this->assertFalse(EndingBalanceSyncBatcher::isActive());
    }

    public function test_duplicate_keys_are_flushed_as_a_single_call(): void
    {
        $mock = $this->bindMock();
        $mock->expects($this->once())
            ->method('syncEbForKlien')
            ->with(10, '2026-07-01', '2026-07-31', 5);

        EndingBalanceSyncBatcher::run(function () {
            EndingBalanceSyncBatcher::collect(10, '2026-07-01', '2026-07-31', 5);
            EndingBalanceSyncBatcher::collect(10, '2026-07-01', '2026-07-31', 5);
            EndingBalanceSyncBatcher::collect(10, '2026-07-01', '2026-07-31', 5);
        });
    }

    public function test_distinct_keys_are_each_flushed_once(): void
    {
        $mock = $this->bindMock();
        $mock->expects($this->exactly(2))->method('syncEbForKlien');

        EndingBalanceSyncBatcher::run(function () {
            EndingBalanceSyncBatcher::collect(10, '2026-07-01', '2026-07-31', 5);
            EndingBalanceSyncBatcher::collect(11, '2026-07-01', '2026-07-31', 5);
        });
    }

    public function test_nothing_is_flushed_while_batch_is_still_active(): void
    {
        $calls = [];
        $mock = $this->createMock(EndingBalanceService::class);
        $mock->method('syncEbForKlien')->willReturnCallback(function (...$args) use (&$calls) {
            $calls[] = $args;
        });
        $this->app->instance(EndingBalanceService::class, $mock);

        EndingBalanceSyncBatcher::run(function () use (&$calls) {
            EndingBalanceSyncBatcher::collect(10, '2026-07-01', '2026-07-31', 5);
            $this->assertCount(0, $calls, 'sync belum boleh jalan selagi batch masih aktif');
        });

        $this->assertCount(1, $calls, 'harus flush tepat setelah batch selesai');
    }

    public function test_nested_run_only_flushes_at_outermost_level(): void
    {
        $mock = $this->bindMock();
        $mock->expects($this->once())->method('syncEbForKlien');

        EndingBalanceSyncBatcher::run(function () {
            EndingBalanceSyncBatcher::collect(10, '2026-07-01', '2026-07-31', 5);

            EndingBalanceSyncBatcher::run(function () {
                EndingBalanceSyncBatcher::collect(10, '2026-07-01', '2026-07-31', 5);
                $this->assertTrue(EndingBalanceSyncBatcher::isActive());
            });

            $this->assertTrue(
                EndingBalanceSyncBatcher::isActive(),
                'masih aktif karena level terluar belum selesai',
            );
        });

        $this->assertFalse(EndingBalanceSyncBatcher::isActive());
    }

    public function test_exception_mid_batch_still_flushes_collected_keys_and_resets_state(): void
    {
        $mock = $this->bindMock();
        $mock->expects($this->once())
            ->method('syncEbForKlien')
            ->with(10, '2026-07-01', '2026-07-31', 5);

        try {
            EndingBalanceSyncBatcher::run(function () {
                EndingBalanceSyncBatcher::collect(10, '2026-07-01', '2026-07-31', 5);
                throw new RuntimeException('boom');
            });
            $this->fail('exception seharusnya diteruskan ke pemanggil, bukan ditelan batcher');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertFalse(
            EndingBalanceSyncBatcher::isActive(),
            'batcher tidak boleh "nyangkut aktif" setelah exception — job/worker berikutnya harus sync normal',
        );
    }

    public function test_one_key_failing_does_not_block_flush_of_other_keys(): void
    {
        $succeeded = [];
        $mock = $this->createMock(EndingBalanceService::class);
        $mock->method('syncEbForKlien')->willReturnCallback(function (int $klienId) use (&$succeeded) {
            if ($klienId === 10) {
                throw new RuntimeException('gagal untuk klien 10');
            }
            $succeeded[] = $klienId;
        });
        $this->app->instance(EndingBalanceService::class, $mock);

        // flush() menangkap exception per-key sendiri, jadi run() tidak boleh melempar apa pun.
        EndingBalanceSyncBatcher::run(function () {
            EndingBalanceSyncBatcher::collect(10, '2026-07-01', '2026-07-31', 5);
            EndingBalanceSyncBatcher::collect(11, '2026-07-01', '2026-07-31', 5);
        });

        $this->assertSame([11], $succeeded);
    }
}
