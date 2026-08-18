<?php

namespace Tests\Feature;

use App\Models\OpeningBalanceImportBatch;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * OpeningBalanceImportBatch — failStale()/cancel()/cancelAbandonedConfirmations()/active(),
 * termasuk sub-state "menunggu konfirmasi" (awaiting_confirmation, dititipkan di kolom
 * `errors` json tanpa migration baru — lihat plan "Perbaikan Re-Import Opening Balance AR").
 * Tabel dibuat ad-hoc (bukan RefreshDatabase — migrate:fresh rusak di project ini, lihat
 * project_test_db_migrate_fresh_broken di memory), skema mengikuti migration asli
 * tb_opening_balance_import_batches.
 */
class OpeningBalanceImportBatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tb_opening_balance_import_batches', function ($table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('original_filename', 255)->nullable();
            $table->string('file_path', 500)->nullable();
            $table->boolean('is_csv')->default(false);
            $table->date('cutover_date')->nullable();
            $table->string('status')->default('queued');
            $table->unsignedInteger('total_ob')->default(0);
            $table->unsignedInteger('processed_ob')->default(0);
            $table->unsignedInteger('inserted_ob')->default(0);
            $table->unsignedInteger('skipped_ob')->default(0);
            $table->unsignedInteger('failed_ob')->default(0);
            $table->unsignedInteger('total_detail')->default(0);
            $table->unsignedInteger('inserted_detail')->default(0);
            $table->unsignedInteger('total_item')->default(0);
            $table->unsignedInteger('inserted_item')->default(0);
            $table->json('errors')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tb_opening_balance_import_batches');
        parent::tearDown();
    }

    private function makeBatch(array $overrides = []): OpeningBalanceImportBatch
    {
        return OpeningBalanceImportBatch::create(array_merge([
            'id' => (string) Str::uuid(),
            'user_id' => 1,
            'status' => 'processing',
        ], $overrides));
    }

    private function ageBatch(OpeningBalanceImportBatch $batch, int $minutesAgo): void
    {
        // update() Eloquent tetap menghormati nilai updated_at yang kita berikan secara
        // eksplisit (addUpdatedAtColumn men-merge nilai kita SETELAH nilai auto, jadi
        // milik kita menang) — beda dari save() yang selalu overwrite ke now().
        OpeningBalanceImportBatch::where('id', $batch->id)->update([
            'updated_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    public function test_fail_stale_mengabaikan_batch_yang_menunggu_konfirmasi(): void
    {
        $batch = $this->makeBatch([
            'errors' => ['failed' => [], 'skipped' => [], 'conflicts' => [['klien_ar_id' => 1]], 'resolution' => null, 'awaiting_confirmation' => true],
        ]);
        $this->ageBatch($batch, 40);

        $count = OpeningBalanceImportBatch::failStale();

        $this->assertSame(0, $count);
        $this->assertSame('processing', $batch->fresh()->status);
    }

    public function test_fail_stale_menandai_gagal_batch_processing_biasa_yang_stale(): void
    {
        $batch = $this->makeBatch(['errors' => null]);
        $this->ageBatch($batch, 40);

        $count = OpeningBalanceImportBatch::failStale();

        $fresh = $batch->fresh();
        $this->assertSame(1, $count);
        $this->assertSame('failed', $fresh->status);
    }

    public function test_fail_stale_tidak_menyentuh_batch_processing_yang_masih_baru(): void
    {
        $batch = $this->makeBatch(['errors' => null]);
        // Tidak di-age — updated_at masih "baru saja" (default now() dari timestamps()).

        $count = OpeningBalanceImportBatch::failStale();

        $this->assertSame(0, $count);
        $this->assertSame('processing', $batch->fresh()->status);
    }

    public function test_cancel_abandoned_confirmations_membatalkan_yang_ditinggalkan(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('opening-balance-imports/stale.csv', 'dummy');

        $batch = $this->makeBatch([
            'file_path' => 'opening-balance-imports/stale.csv',
            'errors' => ['failed' => [], 'skipped' => [], 'conflicts' => [], 'resolution' => null, 'awaiting_confirmation' => true],
        ]);
        $this->ageBatch($batch, 65);

        $count = OpeningBalanceImportBatch::cancelAbandonedConfirmations();

        $fresh = $batch->fresh();
        $this->assertSame(1, $count);
        $this->assertSame('failed', $fresh->status);
        $this->assertStringContainsString('dibatalkan otomatis', $fresh->message);
        Storage::disk('local')->assertMissing('opening-balance-imports/stale.csv');
    }

    public function test_cancel_abandoned_confirmations_tidak_menyentuh_yang_masih_dalam_window(): void
    {
        $batch = $this->makeBatch([
            'errors' => ['failed' => [], 'skipped' => [], 'conflicts' => [], 'resolution' => null, 'awaiting_confirmation' => true],
        ]);
        $this->ageBatch($batch, 10); // di bawah window 60 menit

        $count = OpeningBalanceImportBatch::cancelAbandonedConfirmations();

        $this->assertSame(0, $count);
        $this->assertSame('processing', $batch->fresh()->status);
    }

    public function test_active_menganggap_batch_menunggu_konfirmasi_sebagai_aktif(): void
    {
        $this->makeBatch([
            'errors' => ['failed' => [], 'skipped' => [], 'conflicts' => [], 'resolution' => null, 'awaiting_confirmation' => true],
        ]);

        $this->assertNotNull(OpeningBalanceImportBatch::active());
    }

    public function test_cancel_menghapus_file_dan_menandai_gagal(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('opening-balance-imports/cancel-me.csv', 'dummy');

        $batch = $this->makeBatch(['file_path' => 'opening-balance-imports/cancel-me.csv']);

        $batch->cancel('Import dibatalkan oleh pengguna.');

        $fresh = $batch->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('Import dibatalkan oleh pengguna.', $fresh->message);
        Storage::disk('local')->assertMissing('opening-balance-imports/cancel-me.csv');
    }
}
