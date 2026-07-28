<?php

namespace Tests\Feature;

use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\PembayaranAp\Services\PembayaranApService;
use App\Domain\Finance\PembayaranAr\Services\PembayaranArService;
use App\Domain\Finance\RekonsiliasiBankStatement\Services\BankStatementImportService;
use App\Domain\Finance\RekonsiliasiBankStatement\Services\BankStatementService;
use App\Models\BankStatement;
use App\Models\BankStatementImportBatch;
use App\Models\BankStatementImportRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * End-to-end alur import async rekening koran (BankStatementImportService::run()),
 * dipanggil langsung tanpa lewat ImportBankStatementJob/queue — job cuma wrapper
 * tipis (load batch, Auth::setUser, cleanup file/staging), logika intinya semua
 * di service ini.
 *
 * Menghindari RefreshDatabase (migrate:fresh rusak di project ini — lihat memory
 * project_test_db_migrate_fresh_broken): tabel dibuat ad-hoc per test lalu
 * di-drop lagi, tanpa foreign key constraint (pola yang sama dipakai
 * BankStatementInvoiceCandidatesSearchRestoTest / BankStatementAutoMatchBulkTest).
 */
class BankStatementImportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedSchema();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tb_pembayaran_ar');
        Schema::dropIfExists('tb_bank_statement_detail');
        Schema::dropIfExists('tb_bank_statement');
        Schema::dropIfExists('tb_bank_statement_import_rows');
        Schema::dropIfExists('tb_bank_statement_import_batch');
        Mockery::close();
        parent::tearDown();
    }

    private function seedSchema(): void
    {
        Schema::create('tb_bank_statement_import_batch', function ($table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('bank_type', 20);
            $table->string('original_filename');
            $table->string('file_path', 500)->nullable();
            $table->string('status')->default('queued');
            $table->string('phase', 30)->default('queued');
            $table->text('message')->nullable();
            $table->date('periode_awal')->nullable();
            $table->date('periode_akhir')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('inserted_rows')->default(0);
            $table->unsignedInteger('matched_rows')->default(0);
            $table->unsignedInteger('unmatched_rows')->default(0);
            $table->unsignedInteger('ignored_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->decimal('total_kredit', 15, 2)->default(0);
            $table->unsignedBigInteger('bank_statement_id')->nullable();
            $table->boolean('force_replace')->default(false);
            $table->json('overlaps')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tb_bank_statement_import_rows', function ($table) {
            $table->id();
            $table->uuid('batch_id');
            $table->unsignedInteger('row_number');
            $table->date('tanggal');
            $table->string('waktu_transaksi', 8)->nullable();
            $table->text('keterangan')->nullable();
            $table->string('no_referensi')->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('kredit', 15, 2)->default(0);
            $table->decimal('saldo', 15, 2)->default(0);
        });

        Schema::create('tb_bank_statement', function ($table) {
            $table->id();
            $table->string('bank_type')->default('GENERAL');
            $table->string('nama_file')->nullable();
            $table->date('periode_awal')->nullable();
            $table->date('periode_akhir')->nullable();
            $table->unsignedInteger('total_transaksi')->default(0);
            $table->decimal('total_kredit', 15, 2)->default(0);
            $table->unsignedInteger('jumlah_matched')->default(0);
            $table->unsignedInteger('jumlah_unmatched')->default(0);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
        });

        Schema::create('tb_bank_statement_detail', function ($table) {
            $table->id();
            $table->unsignedBigInteger('bank_statement_id');
            $table->date('tanggal');
            $table->string('waktu_transaksi', 8)->nullable();
            $table->string('keterangan')->nullable();
            $table->string('no_referensi')->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('kredit', 15, 2)->default(0);
            $table->decimal('saldo', 15, 2)->default(0);
            $table->string('status_cocok')->default('UNMATCHED');
            $table->string('status_posting_2')->default('PENDING');
            $table->unsignedBigInteger('pembayaran_ar_id')->nullable();
            $table->unsignedBigInteger('pembayaran_ap_id')->nullable();
            $table->unsignedBigInteger('matched_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamps();
        });

        Schema::create('tb_pembayaran_ar', function ($table) {
            $table->id();
            $table->string('no_referensi')->nullable();
            $table->timestamps();
        });
    }

    private function service(): BankStatementImportService
    {
        return new BankStatementImportService(new BankStatementService(
            Mockery::mock(PembayaranArService::class),
            Mockery::mock(PembayaranApService::class),
            Mockery::mock(InvoiceService::class),
        ));
    }

    private function storeXlsx(array $dataRows, string $name = 'statement.xlsx'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['Tanggal', 'Keterangan', 'No Referensi', 'Debit', 'Kredit', 'Saldo'], null, 'A1');

        $rowIdx = 2;
        foreach ($dataRows as $row) {
            $sheet->fromArray($row, null, "A{$rowIdx}");
            $rowIdx++;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'bs_import_test_') . '.xlsx';
        (new Xlsx($spreadsheet))->save($tmp);

        $path = 'bank-statement-imports/' . $name;
        Storage::disk('local')->put($path, file_get_contents($tmp));
        unlink($tmp);

        return $path;
    }

    private function makeBatch(string $path, array $overrides = []): BankStatementImportBatch
    {
        return BankStatementImportBatch::create(array_merge([
            'user_id'           => 7,
            'bank_type'         => 'GENERAL',
            'original_filename' => 'statement.xlsx',
            'file_path'         => $path,
            'status'            => 'queued',
            'phase'             => 'queued',
        ], $overrides));
    }

    public function test_run_completes_and_creates_statement_with_automatch(): void
    {
        DB::table('tb_pembayaran_ar')->insert([
            'id' => 55, 'no_referensi' => 'TRF001', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $path  = $this->storeXlsx([
            ['01/01/2026', 'Transfer masuk', 'TRF001', '', '500000', '500000'],
            ['02/01/2026', 'Transfer keluar', 'TRF002', '50000', '', '450000'],
        ]);
        $batch = $this->makeBatch($path);

        $this->service()->run($batch);
        $batch->refresh();

        $this->assertSame('completed', $batch->status);
        $this->assertSame('completed', $batch->phase);
        $this->assertSame(2, $batch->total_rows);
        $this->assertSame(2, $batch->inserted_rows);
        $this->assertSame(1, $batch->matched_rows);
        $this->assertSame(1, $batch->unmatched_rows);
        $this->assertNotNull($batch->bank_statement_id);
        $this->assertNotNull($batch->finished_at);

        $statement = BankStatement::find($batch->bank_statement_id);
        $this->assertSame(2, $statement->total_transaksi);
        $this->assertSame('2026-01-01', $statement->periode_awal->toDateString());
        $this->assertSame('2026-01-02', $statement->periode_akhir->toDateString());

        $details = DB::table('tb_bank_statement_detail')->where('bank_statement_id', $statement->id)->get();
        $this->assertCount(2, $details);

        $matched = $details->firstWhere('no_referensi', 'TRF001');
        $this->assertSame('MATCHED', $matched->status_cocok);
        $this->assertSame(55, $matched->pembayaran_ar_id);

        $unmatched = $details->firstWhere('no_referensi', 'TRF002');
        $this->assertSame('UNMATCHED', $unmatched->status_cocok);
    }

    public function test_run_fails_and_creates_no_statement_when_a_row_has_invalid_date(): void
    {
        $path = $this->storeXlsx([
            ['01/01/2026', 'Transfer masuk valid', 'TRF001', '', '500000', '500000'],
            ['tanggal-rusak', 'Baris rusak', '', '', '100000', '600000'],
        ]);
        $batch = $this->makeBatch($path);

        $this->service()->run($batch);
        $batch->refresh();

        $this->assertSame('failed', $batch->status);
        $this->assertSame('failed', $batch->phase);
        $this->assertSame(1, $batch->error_rows);
        $this->assertNotEmpty($batch->errors);
        $this->assertStringContainsString('gagal divalidasi', $batch->message);
        $this->assertNull($batch->bank_statement_id);

        $this->assertSame(0, BankStatement::count());
    }

    public function test_run_needs_confirmation_on_overlap_then_completes_after_force_replace(): void
    {
        $existingId = DB::table('tb_bank_statement')->insertGetId([
            'bank_type' => 'GENERAL', 'nama_file' => 'lama.xlsx',
            'periode_awal' => '2026-01-01', 'periode_akhir' => '2026-01-31',
            'total_transaksi' => 1, 'total_kredit' => 500000,
            'jumlah_matched' => 0, 'jumlah_unmatched' => 1, 'uploaded_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tb_bank_statement_detail')->insert([
            'bank_statement_id' => $existingId, 'tanggal' => '2026-01-10', 'kredit' => 500000, 'debit' => 0,
            'status_cocok' => 'UNMATCHED', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $path  = $this->storeXlsx([
            ['05/01/2026', 'Transfer masuk baru', 'TRF-NEW-1', '', '700000', '700000'],
            ['06/01/2026', 'Transfer keluar baru', 'TRF-NEW-2', '20000', '', '680000'],
        ]);
        $batch = $this->makeBatch($path);

        $this->service()->run($batch);
        $batch->refresh();

        $this->assertSame('needs_confirmation', $batch->status);
        $this->assertSame('needs_confirmation', $batch->phase);
        $this->assertCount(1, $batch->overlaps);
        $this->assertSame($existingId, $batch->overlaps[0]['id']);
        $this->assertSame(2, $batch->total_rows);
        $this->assertNull($batch->bank_statement_id);

        // Statement lama belum tersentuh, staging masih ada (menunggu konfirmasi).
        $this->assertSame(1, BankStatement::count());
        $this->assertSame(2, BankStatementImportRow::where('batch_id', $batch->id)->count());

        // Simulasikan endpoint confirm-replace: set force_replace, jalankan lagi.
        $batch->update(['force_replace' => true, 'status' => 'queued', 'phase' => 'queued']);
        $this->service()->run($batch->fresh());
        $batch->refresh();

        $this->assertSame('completed', $batch->status);
        $this->assertNotNull($batch->bank_statement_id);
        $this->assertNotSame($existingId, $batch->bank_statement_id);

        // Statement lama sudah diganti, hanya statement baru yang tersisa.
        $this->assertSame(0, BankStatement::where('id', $existingId)->count());
        $this->assertSame(1, BankStatement::count());

        $newStatement = BankStatement::find($batch->bank_statement_id);
        $this->assertSame(2, $newStatement->total_transaksi);
    }
}
