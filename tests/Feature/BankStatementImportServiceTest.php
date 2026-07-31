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
            $table->boolean('is_committed')->default(true);
            $table->uuid('import_batch_id')->nullable();
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
        $this->assertTrue((bool) $statement->is_committed);
        $this->assertSame($batch->id, $statement->import_batch_id);

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
        $oldDetailId = DB::table('tb_bank_statement_detail')->insertGetId([
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

        // Baris lama ini legit ikut diganti/dihapus KARENA statusnya UNMATCHED
        // (tidak ada pembayaran yang terhubung) — kontraskan dengan baris
        // MATCHED/DIABAIKAN yang harus dipertahankan, diuji di test-test di bawah.
        $this->assertSame(0, DB::table('tb_bank_statement_detail')->where('id', $oldDetailId)->count());

        $newStatement = BankStatement::find($batch->bank_statement_id);
        $this->assertSame(2, $newStatement->total_transaksi);
        $this->assertTrue((bool) $newStatement->is_committed);
    }

    public function test_run_preserves_matched_ar_row_and_does_not_corrupt_it_via_automatch(): void
    {
        // Regression guard untuk temuan kritis: autoMatch() tanpa onlyUnmatched=true
        // akan mengevaluasi ulang baris hasil reparent, gagal menemukan kandidat
        // (PembayaranAr-nya sendiri sudah "dipakai"), lalu diam-diam meng-UNMATCH
        // baris yang justru harus dilindungi.
        DB::table('tb_pembayaran_ar')->insert([
            'id' => 55, 'no_referensi' => 'TRF001', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $existingId = DB::table('tb_bank_statement')->insertGetId([
            'bank_type' => 'GENERAL', 'nama_file' => 'lama.xlsx',
            'periode_awal' => '2026-01-01', 'periode_akhir' => '2026-01-31',
            'total_transaksi' => 1, 'total_kredit' => 500000,
            'jumlah_matched' => 1, 'jumlah_unmatched' => 0, 'uploaded_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $oldDetailId = DB::table('tb_bank_statement_detail')->insertGetId([
            'bank_statement_id' => $existingId, 'tanggal' => '2026-01-10',
            'no_referensi' => 'TRF001', 'kredit' => 500000, 'debit' => 0,
            'status_cocok' => 'MATCHED', 'pembayaran_ar_id' => 55, 'matched_by' => 3,
            'status_posting_2' => 'POSTED', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $path  = $this->storeXlsx([
            ['10/01/2026', 'Transfer masuk (sama)', 'TRF001', '', '500000', '500000'],
            ['15/01/2026', 'Transfer masuk baru', 'TRF-NEW-1', '', '200000', '700000'],
        ]);
        $batch = $this->makeBatch($path);

        $this->service()->run($batch);
        $batch->refresh();
        $this->assertSame('needs_confirmation', $batch->status);

        $batch->update(['force_replace' => true, 'status' => 'queued', 'phase' => 'queued']);
        $this->service()->run($batch->fresh());
        $batch->refresh();

        $this->assertSame('completed', $batch->status);
        $this->assertSame(0, BankStatement::where('id', $existingId)->count());

        $newStatementId = $batch->bank_statement_id;
        $this->assertNotNull($newStatementId);

        $preserved = DB::table('tb_bank_statement_detail')->find($oldDetailId);
        $this->assertNotNull($preserved, 'Baris matched lama harus tetap ada (di-reparent), bukan dihapus/dibuat ulang.');
        $this->assertSame($newStatementId, $preserved->bank_statement_id);
        $this->assertSame('MATCHED', $preserved->status_cocok);
        $this->assertSame(55, $preserved->pembayaran_ar_id);
        $this->assertSame(3, $preserved->matched_by);

        $details = DB::table('tb_bank_statement_detail')->where('bank_statement_id', $newStatementId)->get();
        $this->assertCount(2, $details);

        $this->assertStringContainsString('dipertahankan', $batch->message);
    }

    public function test_run_preserves_matched_ap_voucher_row_on_reupload(): void
    {
        $existingId = DB::table('tb_bank_statement')->insertGetId([
            'bank_type' => 'GENERAL', 'nama_file' => 'lama.xlsx',
            'periode_awal' => '2026-01-01', 'periode_akhir' => '2026-01-31',
            'total_transaksi' => 1, 'total_kredit' => 0,
            'jumlah_matched' => 1, 'jumlah_unmatched' => 0, 'uploaded_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $oldDetailId = DB::table('tb_bank_statement_detail')->insertGetId([
            'bank_statement_id' => $existingId, 'tanggal' => '2026-01-12',
            'no_referensi' => 'VCH001', 'kredit' => 0, 'debit' => 300000,
            'status_cocok' => 'MATCHED', 'pembayaran_ap_id' => 77,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $path  = $this->storeXlsx([
            ['12/01/2026', 'Transfer keluar (sama)', 'VCH001', '300000', '', '400000'],
        ]);
        $batch = $this->makeBatch($path);
        $this->service()->run($batch);
        $batch->update(['force_replace' => true, 'status' => 'queued', 'phase' => 'queued']);
        $this->service()->run($batch->fresh());
        $batch->refresh();

        $this->assertSame('completed', $batch->status);

        $preserved = DB::table('tb_bank_statement_detail')->find($oldDetailId);
        $this->assertNotNull($preserved);
        $this->assertSame($batch->bank_statement_id, $preserved->bank_statement_id);
        $this->assertSame('MATCHED', $preserved->status_cocok);
        $this->assertSame(77, $preserved->pembayaran_ap_id);
    }

    public function test_run_preserves_diabaikan_row_on_reupload(): void
    {
        $existingId = DB::table('tb_bank_statement')->insertGetId([
            'bank_type' => 'GENERAL', 'nama_file' => 'lama.xlsx',
            'periode_awal' => '2026-01-01', 'periode_akhir' => '2026-01-31',
            'total_transaksi' => 1, 'total_kredit' => 1500,
            'jumlah_matched' => 0, 'jumlah_unmatched' => 0, 'uploaded_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $oldDetailId = DB::table('tb_bank_statement_detail')->insertGetId([
            'bank_statement_id' => $existingId, 'tanggal' => '2026-01-08',
            'no_referensi' => 'BUNGA-JAN', 'kredit' => 1500, 'debit' => 0,
            'status_cocok' => 'DIABAIKAN',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $path  = $this->storeXlsx([
            ['08/01/2026', 'Bunga bank (sama)', 'BUNGA-JAN', '', '1500', '501500'],
        ]);
        $batch = $this->makeBatch($path);
        $this->service()->run($batch);
        $batch->update(['force_replace' => true, 'status' => 'queued', 'phase' => 'queued']);
        $this->service()->run($batch->fresh());
        $batch->refresh();

        $this->assertSame('completed', $batch->status);

        $preserved = DB::table('tb_bank_statement_detail')->find($oldDetailId);
        $this->assertNotNull($preserved);
        $this->assertSame($batch->bank_statement_id, $preserved->bank_statement_id);
        $this->assertSame('DIABAIKAN', $preserved->status_cocok);
    }

    public function test_run_treats_same_no_referensi_with_different_amount_as_separate_row(): void
    {
        DB::table('tb_pembayaran_ar')->insert([
            'id' => 55, 'no_referensi' => 'TRF-KOREKSI', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $existingId = DB::table('tb_bank_statement')->insertGetId([
            'bank_type' => 'GENERAL', 'nama_file' => 'lama.xlsx',
            'periode_awal' => '2026-01-01', 'periode_akhir' => '2026-01-31',
            'total_transaksi' => 1, 'total_kredit' => 500000,
            'jumlah_matched' => 1, 'jumlah_unmatched' => 0, 'uploaded_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $oldDetailId = DB::table('tb_bank_statement_detail')->insertGetId([
            'bank_statement_id' => $existingId, 'tanggal' => '2026-01-10',
            'no_referensi' => 'TRF-KOREKSI', 'kredit' => 500000, 'debit' => 0,
            'status_cocok' => 'MATCHED', 'pembayaran_ar_id' => 55, 'matched_by' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // File baru: no_referensi sama tapi nominal beda (mis. koreksi dari bank).
        $path  = $this->storeXlsx([
            ['10/01/2026', 'Transfer masuk (koreksi nominal)', 'TRF-KOREKSI', '', '600000', '600000'],
        ]);
        $batch = $this->makeBatch($path);
        $this->service()->run($batch);
        $batch->update(['force_replace' => true, 'status' => 'queued', 'phase' => 'queued']);
        $this->service()->run($batch->fresh());
        $batch->refresh();

        $this->assertSame('completed', $batch->status);

        // Baris lama tetap dipertahankan apa adanya.
        $preserved = DB::table('tb_bank_statement_detail')->find($oldDetailId);
        $this->assertNotNull($preserved);
        $this->assertEqualsWithDelta(500000.0, (float) $preserved->kredit, 0.01);
        $this->assertSame('MATCHED', $preserved->status_cocok);

        // Baris baru dianggap transaksi terpisah, tetap di-insert.
        $newRow = DB::table('tb_bank_statement_detail')
            ->where('bank_statement_id', $batch->bank_statement_id)
            ->where('no_referensi', 'TRF-KOREKSI')
            ->where('kredit', 600000)
            ->first();
        $this->assertNotNull($newRow);
        $this->assertNotSame($oldDetailId, $newRow->id);

        $this->assertCount(2, DB::table('tb_bank_statement_detail')->where('bank_statement_id', $batch->bank_statement_id)->get());
    }

    public function test_run_preserves_matched_row_across_multiple_reuploads(): void
    {
        DB::table('tb_pembayaran_ar')->insert([
            'id' => 55, 'no_referensi' => 'TRF001', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $existingId = DB::table('tb_bank_statement')->insertGetId([
            'bank_type' => 'GENERAL', 'nama_file' => 'lama.xlsx',
            'periode_awal' => '2026-01-01', 'periode_akhir' => '2026-01-31',
            'total_transaksi' => 1, 'total_kredit' => 500000,
            'jumlah_matched' => 1, 'jumlah_unmatched' => 0, 'uploaded_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $oldDetailId = DB::table('tb_bank_statement_detail')->insertGetId([
            'bank_statement_id' => $existingId, 'tanggal' => '2026-01-10',
            'no_referensi' => 'TRF001', 'kredit' => 500000, 'debit' => 0,
            'status_cocok' => 'MATCHED', 'pembayaran_ar_id' => 55, 'matched_by' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Reupload #1
        $path1  = $this->storeXlsx([
            ['10/01/2026', 'Transfer masuk', 'TRF001', '', '500000', '500000'],
        ], 'r1.xlsx');
        $batch1 = $this->makeBatch($path1);
        $this->service()->run($batch1);
        $batch1->update(['force_replace' => true, 'status' => 'queued', 'phase' => 'queued']);
        $this->service()->run($batch1->fresh());
        $batch1->refresh();
        $this->assertSame('completed', $batch1->status);

        $preservedAfter1 = DB::table('tb_bank_statement_detail')->find($oldDetailId);
        $this->assertSame($batch1->bank_statement_id, $preservedAfter1->bank_statement_id);
        $this->assertSame('MATCHED', $preservedAfter1->status_cocok);

        // Reupload #2, periode yang sama lagi.
        $path2  = $this->storeXlsx([
            ['10/01/2026', 'Transfer masuk', 'TRF001', '', '500000', '500000'],
        ], 'r2.xlsx');
        $batch2 = $this->makeBatch($path2);
        $this->service()->run($batch2);
        $batch2->update(['force_replace' => true, 'status' => 'queued', 'phase' => 'queued']);
        $this->service()->run($batch2->fresh());
        $batch2->refresh();
        $this->assertSame('completed', $batch2->status);

        dump('DEBUG statements', BankStatement::all(['id', 'periode_awal', 'periode_akhir'])->toArray());
        dump('DEBUG details', DB::table('tb_bank_statement_detail')->get(['id', 'bank_statement_id', 'no_referensi', 'status_cocok'])->toArray());
        dump('DEBUG batch2', $batch2->only(['id', 'bank_statement_id', 'status', 'message']));

        $preservedAfter2 = DB::table('tb_bank_statement_detail')->find($oldDetailId);
        $this->assertSame($batch2->bank_statement_id, $preservedAfter2->bank_statement_id);
        $this->assertSame('MATCHED', $preservedAfter2->status_cocok);
        $this->assertSame(55, $preservedAfter2->pembayaran_ar_id);

        $this->assertSame(1, BankStatement::count());
    }

    public function test_run_recomputes_header_period_to_include_reparented_rows(): void
    {
        DB::table('tb_pembayaran_ar')->insert([
            'id' => 55, 'no_referensi' => 'TRF001', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $existingId = DB::table('tb_bank_statement')->insertGetId([
            'bank_type' => 'GENERAL', 'nama_file' => 'lama.xlsx',
            'periode_awal' => '2026-01-01', 'periode_akhir' => '2026-01-31',
            'total_transaksi' => 1, 'total_kredit' => 500000,
            'jumlah_matched' => 1, 'jumlah_unmatched' => 0, 'uploaded_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tb_bank_statement_detail')->insertGetId([
            'bank_statement_id' => $existingId, 'tanggal' => '2026-01-25',
            'no_referensi' => 'TRF001', 'kredit' => 500000, 'debit' => 0,
            'status_cocok' => 'MATCHED', 'pembayaran_ar_id' => 55,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // File baru hanya mencakup awal bulan — tidak menyentuh baris tanggal 25.
        $path  = $this->storeXlsx([
            ['05/01/2026', 'Transfer masuk baru', 'TRF-NEW-1', '', '200000', '200000'],
        ]);
        $batch = $this->makeBatch($path);
        $this->service()->run($batch);
        $batch->update(['force_replace' => true, 'status' => 'queued', 'phase' => 'queued']);
        $this->service()->run($batch->fresh());
        $batch->refresh();

        $newStatement = BankStatement::find($batch->bank_statement_id);
        $this->assertSame('2026-01-05', $newStatement->periode_awal->toDateString());
        $this->assertSame('2026-01-25', $newStatement->periode_akhir->toDateString());
        $this->assertSame(2, $newStatement->total_transaksi);
        $this->assertEqualsWithDelta(700000.0, (float) $newStatement->total_kredit, 0.01);
    }

    public function test_run_resets_stale_counters_left_over_from_a_previous_run(): void
    {
        // Simulasikan batch yang sebelumnya sempat diproses (mis. file besar lalu
        // confirm-replace) sehingga menyisakan counter besar di kolom-kolom ini.
        // Tanpa reset di awal run(), counter lama ini akan ikut terbaca FE lewat
        // polling sebelum job baru sempat menimpanya — sumber bug "24020 / 15770".
        $path  = $this->storeXlsx([
            ['01/01/2026', 'Transfer masuk', 'TRF001', '', '500000', '500000'],
            ['02/01/2026', 'Transfer keluar', 'TRF002', '50000', '', '450000'],
        ]);
        $batch = $this->makeBatch($path, [
            'total_rows'     => 15770,
            'processed_rows' => 24020,
            'inserted_rows'  => 15770,
            'matched_rows'   => 9000,
            'unmatched_rows' => 6770,
            'ignored_rows'   => 100,
            'error_rows'     => 3,
            'total_kredit'   => 999999999,
            'errors'         => [['row' => 1, 'message' => 'error lama']],
        ]);

        $this->service()->run($batch);
        $batch->refresh();

        $this->assertSame('completed', $batch->status);
        $this->assertSame(2, $batch->total_rows);
        $this->assertSame(2, $batch->processed_rows);
        $this->assertSame(2, $batch->inserted_rows);
        $this->assertSame(0, $batch->matched_rows);
        $this->assertSame(2, $batch->unmatched_rows);
        $this->assertSame(0, $batch->error_rows);
        $this->assertEmpty($batch->errors);
        $this->assertEqualsWithDelta(500000.0, (float) $batch->total_kredit, 0.01);
    }

    public function test_cancel_marks_batch_failed_and_cleans_staging_and_file(): void
    {
        $path = $this->storeXlsx([
            ['05/01/2026', 'Transfer masuk baru', 'TRF-NEW-1', '', '700000', '700000'],
        ]);
        $batch = $this->makeBatch($path, [
            'status' => 'needs_confirmation',
            'phase'  => 'needs_confirmation',
        ]);
        BankStatementImportRow::insert([
            'batch_id' => $batch->id, 'row_number' => 1, 'tanggal' => '2026-01-05',
            'keterangan' => 'x', 'debit' => 0, 'kredit' => 700000, 'saldo' => 700000,
        ]);

        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertSame(1, BankStatementImportRow::where('batch_id', $batch->id)->count());

        $batch->cancel('Import dibatalkan oleh pengguna.');
        $batch->refresh();

        $this->assertSame('failed', $batch->status);
        $this->assertSame('canceled', $batch->phase);
        $this->assertSame('Import dibatalkan oleh pengguna.', $batch->message);
        $this->assertNotNull($batch->finished_at);
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertSame(0, BankStatementImportRow::where('batch_id', $batch->id)->count());
    }

    public function test_cancel_abandoned_confirmations_cleans_up_stale_batches(): void
    {
        $path = $this->storeXlsx([
            ['05/01/2026', 'Transfer masuk baru', 'TRF-NEW-1', '', '700000', '700000'],
        ]);
        $batch = $this->makeBatch($path, [
            'status' => 'needs_confirmation',
            'phase'  => 'needs_confirmation',
        ]);
        BankStatementImportRow::insert([
            'batch_id' => $batch->id, 'row_number' => 1, 'tanggal' => '2026-01-05',
            'keterangan' => 'x', 'debit' => 0, 'kredit' => 700000, 'saldo' => 700000,
        ]);

        // Simulasikan batch yang sudah lama ditinggal (updated_at melewati ambang).
        DB::table('tb_bank_statement_import_batch')->where('id', $batch->id)
            ->update(['updated_at' => now()->subMinutes(90)]);

        $count = BankStatementImportBatch::cancelAbandonedConfirmations(60);

        $this->assertSame(1, $count);

        $batch->refresh();
        $this->assertSame('failed', $batch->status);
        $this->assertSame('canceled', $batch->phase);
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertSame(0, BankStatementImportRow::where('batch_id', $batch->id)->count());
    }

    public function test_cleanup_stale_imports_command_marks_stale_processing_batch_as_failed(): void
    {
        $path  = $this->storeXlsx([
            ['05/01/2026', 'Transfer masuk baru', 'TRF-NEW-2', '', '700000', '700000'],
        ]);
        $batch = $this->makeBatch($path, ['status' => 'processing', 'phase' => 'saving']);

        // Simulasikan worker yang mati di tengah proses (updated_at melewati ambang 35 menit).
        DB::table('tb_bank_statement_import_batch')->where('id', $batch->id)
            ->update(['updated_at' => now()->subMinutes(40)]);

        $this->artisan('bank-statement:cleanup-stale-imports')->assertSuccessful();

        $batch->refresh();
        $this->assertSame('failed', $batch->status);
        $this->assertSame('failed', $batch->phase);
    }
}
