<?php

namespace Tests\Feature;

use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\PembayaranAp\Services\PembayaranApService;
use App\Domain\Finance\PembayaranAr\Services\PembayaranArService;
use App\Domain\Finance\RekonsiliasiBankStatement\Services\BankStatementService;
use App\Models\BankStatement;
use App\Models\BankStatementDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * BankStatementService::autoMatch() dirombak dari loop per-baris (1 query SELECT
 * per baris kredit) menjadi batch: 1 query whereIn() untuk semua kandidat
 * PembayaranAr, lalu bulk UPDATE ber-CASE WHEN. Test ini memverifikasi hasil
 * akhirnya identik dengan semantik lama: match by no_referensi, 1 PembayaranAr
 * hanya boleh dipakai oleh 1 detail (baik dari histori maupun dalam run yang sama).
 *
 * Menghindari RefreshDatabase (lihat project_test_db_migrate_fresh_broken di
 * memory) — tabel dibuat ad-hoc lalu di-drop lagi, tanpa foreign key constraint
 * (pola yang sama dipakai BankStatementInvoiceCandidatesSearchRestoTest).
 */
class BankStatementAutoMatchBulkTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('tb_pembayaran_ar');
        Schema::dropIfExists('tb_bank_statement_detail');
        Schema::dropIfExists('tb_bank_statement');
        Mockery::close();
        parent::tearDown();
    }

    private function service(): BankStatementService
    {
        return new BankStatementService(
            Mockery::mock(PembayaranArService::class),
            Mockery::mock(PembayaranApService::class),
            Mockery::mock(InvoiceService::class),
        );
    }

    private function seedSchema(): void
    {
        Schema::create('tb_bank_statement', function ($table) {
            $table->id();
            $table->string('bank_type')->default('GENERAL');
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
            $table->string('keterangan')->nullable();
            $table->string('no_referensi')->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('kredit', 15, 2)->default(0);
            $table->decimal('saldo', 15, 2)->default(0);
            $table->string('status_cocok')->default('UNMATCHED');
            $table->string('status_posting_2')->default('PENDING');
            $table->unsignedBigInteger('pembayaran_ar_id')->nullable();
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

    public function test_auto_match_batch_matches_by_no_referensi_and_dedupes_duplicate_refs(): void
    {
        $this->seedSchema();

        $statementId = DB::table('tb_bank_statement')->insertGetId([
            'jumlah_matched' => 0, 'jumlah_unmatched' => 0, 'uploaded_by' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('tb_pembayaran_ar')->insert([
            'id' => 10, 'no_referensi' => 'REF-A', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // A & D sama-sama REF-A tapi cuma 1 PembayaranAr yang tersedia -> hanya salah
        // satu (yang lebih dulu berdasar urutan tanggal, id) yang boleh match.
        $detailA = DB::table('tb_bank_statement_detail')->insertGetId([
            'bank_statement_id' => $statementId, 'tanggal' => '2026-01-01', 'kredit' => 500000, 'debit' => 0,
            'no_referensi' => 'REF-A', 'status_cocok' => 'UNMATCHED', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $detailB = DB::table('tb_bank_statement_detail')->insertGetId([
            'bank_statement_id' => $statementId, 'tanggal' => '2026-01-02', 'kredit' => 300000, 'debit' => 0,
            'no_referensi' => 'REF-B', 'status_cocok' => 'UNMATCHED', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $detailC = DB::table('tb_bank_statement_detail')->insertGetId([
            'bank_statement_id' => $statementId, 'tanggal' => '2026-01-03', 'kredit' => 200000, 'debit' => 0,
            'no_referensi' => null, 'status_cocok' => 'UNMATCHED', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $detailD = DB::table('tb_bank_statement_detail')->insertGetId([
            'bank_statement_id' => $statementId, 'tanggal' => '2026-01-04', 'kredit' => 400000, 'debit' => 0,
            'no_referensi' => 'REF-A', 'status_cocok' => 'UNMATCHED', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $statement = BankStatement::find($statementId);
        $this->service()->autoMatch($statement);

        $a = BankStatementDetail::find($detailA);
        $this->assertSame('MATCHED', $a->status_cocok);
        $this->assertSame(10, $a->pembayaran_ar_id);
        $this->assertSame(99, $a->matched_by);
        $this->assertSame('POSTED', $a->status_posting_2);
        $this->assertNotNull($a->posted_at);
        $this->assertSame(99, $a->posted_by);

        $d = BankStatementDetail::find($detailD);
        $this->assertSame('UNMATCHED', $d->status_cocok, 'REF-A kedua tidak boleh ikut match karena kandidatnya sudah dipakai detail A.');
        $this->assertNull($d->pembayaran_ar_id);

        $b = BankStatementDetail::find($detailB);
        $this->assertSame('UNMATCHED', $b->status_cocok, 'REF-B tidak punya kandidat PembayaranAr sama sekali.');

        $c = BankStatementDetail::find($detailC);
        $this->assertSame('UNMATCHED', $c->status_cocok, 'no_referensi kosong tidak pernah match.');

        $statement->refresh();
        $this->assertSame(1, $statement->jumlah_matched);
        $this->assertSame(3, $statement->jumlah_unmatched);
    }

    public function test_auto_match_respects_pembayaran_already_used_by_previous_matched_detail(): void
    {
        $this->seedSchema();

        $statementId = DB::table('tb_bank_statement')->insertGetId([
            'jumlah_matched' => 1, 'jumlah_unmatched' => 0, 'uploaded_by' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('tb_pembayaran_ar')->insert([
            'id' => 20, 'no_referensi' => 'REF-X', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Detail lama yang sudah MATCHED ke pembayaran 20 (mis. hasil match manual sebelumnya).
        DB::table('tb_bank_statement_detail')->insert([
            'bank_statement_id' => $statementId, 'tanggal' => '2026-01-01', 'kredit' => 500000, 'debit' => 0,
            'no_referensi' => 'REF-X', 'status_cocok' => 'MATCHED', 'pembayaran_ar_id' => 20,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Baris baru dengan referensi sama — tidak boleh ikut mengklaim pembayaran 20.
        $newDetailId = DB::table('tb_bank_statement_detail')->insertGetId([
            'bank_statement_id' => $statementId, 'tanggal' => '2026-01-05', 'kredit' => 500000, 'debit' => 0,
            'no_referensi' => 'REF-X', 'status_cocok' => 'UNMATCHED',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $statement = BankStatement::find($statementId);
        $this->service()->autoMatch($statement, onlyUnmatched: true);

        $newDetail = BankStatementDetail::find($newDetailId);
        $this->assertSame('UNMATCHED', $newDetail->status_cocok);
        $this->assertNull($newDetail->pembayaran_ar_id);
    }
}
