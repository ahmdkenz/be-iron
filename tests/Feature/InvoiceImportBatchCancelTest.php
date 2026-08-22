<?php

namespace Tests\Feature;

use App\Models\InvoiceImportBatch;
use App\Models\InvoiceImportGroup;
use App\Models\InvoiceImportRow;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * InvoiceImportBatch::cancel() — meniru pola BankStatementImportBatch::cancel()
 * (lihat memory project_rekening_koran_anti_partial_import) untuk membatalkan
 * batch import invoice yang menggantung di awaiting_review.
 *
 * Ad-hoc Schema::create dipakai karena migrate:fresh rusak di project ini
 * (lihat memory project_test_db_migrate_fresh_broken) — pola yang sama dengan
 * BankStatementImportServiceTest.
 */
class InvoiceImportBatchCancelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedSchema();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tb_invoice_import_rows');
        Schema::dropIfExists('tb_invoice_import_groups');
        Schema::dropIfExists('tb_invoice_import_batches');
        parent::tearDown();
    }

    private function seedSchema(): void
    {
        Schema::create('tb_invoice_import_batches', function ($table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('original_filename')->nullable();
            $table->string('file_path')->nullable();
            $table->string('status', 30)->default('queued');
            $table->string('phase', 30)->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('parsed_rows')->default(0);
            $table->unsignedInteger('total_groups')->default(0);
            $table->unsignedInteger('classified_groups')->default(0);
            $table->unsignedInteger('cnt_new')->default(0);
            $table->unsignedInteger('cnt_unchanged')->default(0);
            $table->unsignedInteger('cnt_safe_update')->default(0);
            $table->unsignedInteger('cnt_review_required')->default(0);
            $table->unsignedInteger('cnt_rejected')->default(0);
            $table->unsignedInteger('applied_total')->default(0);
            $table->unsignedInteger('applied_processed')->default(0);
            $table->unsignedInteger('applied_inserted')->default(0);
            $table->unsignedInteger('applied_updated')->default(0);
            $table->unsignedInteger('applied_skipped')->default(0);
            $table->unsignedInteger('applied_failed')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('classified_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tb_invoice_import_groups', function ($table) {
            $table->id();
            $table->uuid('batch_id');
            $table->string('group_key', 191);
            $table->string('tipe_invoice', 5)->nullable();
            $table->string('nama_klien')->nullable();
            $table->string('kode_resto', 100)->nullable();
            $table->date('tanggal_invoice')->nullable();
            $table->date('tanggal_jatuh_tempo')->nullable();
            $table->string('no_surat_jalan')->nullable();
            $table->text('keterangan')->nullable();
            $table->unsignedInteger('first_line')->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedBigInteger('klien_ar_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('no_invoice')->nullable();
            $table->string('classification', 20)->default('REJECTED');
            $table->text('reason')->nullable();
            $table->json('risk_flags')->nullable();
            $table->json('header_diff')->nullable();
            $table->decimal('total_lama', 15, 2)->default(0);
            $table->decimal('total_baru', 15, 2)->default(0);
            $table->decimal('selisih', 15, 2)->default(0);
            $table->string('apply_status', 20)->nullable();
            $table->text('apply_message')->nullable();
            $table->timestamps();
        });

        Schema::create('tb_invoice_import_rows', function ($table) {
            $table->id();
            $table->uuid('batch_id');
            $table->unsignedBigInteger('group_id')->nullable();
            $table->unsignedInteger('row_number');
            $table->string('no_invoice_resto')->nullable();
            $table->string('kode_resto', 100)->nullable();
            $table->string('nama_resto')->nullable();
            $table->string('kode_barang', 100)->nullable();
            $table->string('nama_barang')->nullable();
            $table->unsignedBigInteger('barang_id')->nullable();
            $table->decimal('qty', 15, 2)->default(0);
            $table->string('satuan', 50)->nullable();
            $table->decimal('harga_satuan', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    private function makeBatch(array $overrides = []): InvoiceImportBatch
    {
        return InvoiceImportBatch::create(array_merge([
            'id'                => (string) Str::uuid(),
            'user_id'           => 1,
            'original_filename' => 'invoice.xlsx',
            'file_path'         => null,
            'status'            => 'awaiting_review',
            'phase'             => 'awaiting_review',
        ], $overrides));
    }

    public function test_cancel_deletes_file_groups_and_rows_and_marks_batch_canceled(): void
    {
        Storage::disk('local')->put('invoice-imports/foo.xlsx', 'dummy');
        $batch = $this->makeBatch(['file_path' => 'invoice-imports/foo.xlsx']);

        $group = InvoiceImportGroup::create([
            'batch_id'       => $batch->id,
            'group_key'      => 'B2B||1||2026-07-01',
            'classification' => 'NEW_INVOICE',
        ]);
        InvoiceImportRow::insert([
            'batch_id' => $batch->id, 'group_id' => $group->id, 'row_number' => 1,
            'qty' => 1, 'harga_satuan' => 1000, 'subtotal' => 1000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue(Storage::disk('local')->exists('invoice-imports/foo.xlsx'));

        $batch->cancel('Dibatalkan oleh test.');
        $batch->refresh();

        $this->assertSame('failed', $batch->status);
        $this->assertSame('canceled', $batch->phase);
        $this->assertSame('Dibatalkan oleh test.', $batch->message);
        $this->assertNotNull($batch->finished_at);
        $this->assertFalse(Storage::disk('local')->exists('invoice-imports/foo.xlsx'));
        $this->assertSame(0, InvoiceImportGroup::where('batch_id', $batch->id)->count());
        $this->assertSame(0, InvoiceImportRow::where('batch_id', $batch->id)->count());
    }

    public function test_cancel_does_not_leave_orphan_rows_behind(): void
    {
        // Regression guard: FK group_id di tb_invoice_import_rows cuma nullOnDelete
        // (bukan cascade) pada migration aslinya, jadi cancel() WAJIB hapus rows()
        // SEBELUM groups() supaya tidak ada baris yang tertinggal dengan group_id
        // null. Skema ad-hoc di sini tidak mendeklarasikan FK constraint sungguhan,
        // jadi yang diverifikasi murni urutan/hasil akhir method-nya.
        $batch = $this->makeBatch();
        $group = InvoiceImportGroup::create([
            'batch_id' => $batch->id, 'group_key' => 'k', 'classification' => 'REVIEW_REQUIRED',
        ]);
        InvoiceImportRow::insert([
            'batch_id' => $batch->id, 'group_id' => $group->id, 'row_number' => 1,
            'qty' => 1, 'harga_satuan' => 1, 'subtotal' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $batch->cancel('x');

        $this->assertSame(0, InvoiceImportRow::where('batch_id', $batch->id)->count());
    }

    public function test_cancel_does_not_touch_other_batches(): void
    {
        $batch1 = $this->makeBatch();
        $batch2 = $this->makeBatch();
        InvoiceImportGroup::create(['batch_id' => $batch2->id, 'group_key' => 'k2', 'classification' => 'NEW_INVOICE']);

        $batch1->cancel('x');

        $this->assertSame(1, InvoiceImportGroup::where('batch_id', $batch2->id)->count());
        $batch2->refresh();
        $this->assertSame('awaiting_review', $batch2->status);
    }

    public function test_cancel_keeps_batch_row_for_audit_trail(): void
    {
        $batch = $this->makeBatch();

        $batch->cancel('Dibatalkan oleh test.');

        $this->assertNotNull(InvoiceImportBatch::find($batch->id));
    }
}
