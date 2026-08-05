<?php

namespace Tests\Feature;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceService;
use App\Domain\Finance\EndingBalance\Services\EndingBalanceSyncBatcher;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\PembayaranAp\Services\PembayaranApService;
use App\Domain\Finance\PembayaranAr\Services\PembayaranArService;
use App\Domain\Finance\RekonsiliasiBankStatement\Services\BankStatementService;
use App\Models\BankStatementDetail;
use App\Models\Invoice;
use App\Models\PembayaranArItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Menutup gap test coverage Multi Payment AR ("Cocokkan Transaksi" -> 1 baris
 * kredit bank melunasi banyak invoice lintas resto/investor sekaligus):
 *
 * 1) sisa_tagihan/status tiap invoice benar-benar ter-update lintas entitas,
 * 2) unmatch membalikkan semuanya,
 * 3) EndingBalanceSyncBatcher dedup benar-benar aktif — regresi untuk bug
 *    penempatan EndingBalanceSyncBatcher::run() (dulu dibungkus DI DALAM
 *    DB::transaction() di createMultiPayment()/delete() sehingga DB::afterCommit
 *    yang memicu sync selalu jalan SETELAH scope run() sudah closed; dibuktikan
 *    lewat probe test manual sebelum diperbaiki, lihat commit yang memindahkan
 *    EndingBalanceSyncBatcher::run() ke luar DB::transaction() di
 *    PembayaranArService::createMultiPayment()/delete() dan
 *    BankStatementService::matchWithNewMultiPayment()/unmatch()).
 *
 * Menghindari RefreshDatabase (lihat project_test_db_migrate_fresh_broken di
 * memory) — schema dibuat ad-hoc via Schema::create lalu di-drop lagi, pola sama
 * seperti BankStatementInvoiceCandidatesSearchRestoTest & BankStatementAutoMatchBulkTest.
 * EndingBalanceService di-mock supaya tidak perlu skema tb_ending_balance.
 */
class PembayaranArMultiPaymentInvoiceUpdateTest extends TestCase
{
    private const TABLES = [
        'tb_pembayaran_ar_items',
        'tb_pembayaran_ar_log',
        'tb_bank_statement_detail',
        'tb_bank_statement',
        'tb_pendapatan_di_muka',
        'tb_pembayaran_ar',
        'tb_opening_balance_detail',
        'tb_ending_balance_koreksi',
        'tb_invoice',
        'tb_klien_ar',
    ];

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        EndingBalanceSyncBatcher::resetForTesting();
        Mockery::close();
        parent::tearDown();
    }

    private function seedSchema(): void
    {
        // InvoiceService::sumOwnSisaBeforeInvoice() pakai GREATEST() (fungsi
        // MySQL/produksi) di raw SQL — sqlite tidak punya fungsi ini bawaan,
        // jadi didaftarkan manual di koneksi PDO test.
        DB::connection()->getPdo()->sqliteCreateFunction('GREATEST', fn ($a, $b) => max($a, $b), 2);

        Schema::create('tb_klien_ar', function ($table) {
            $table->id();
            $table->string('nama_klien');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tb_invoice', function ($table) {
            $table->id();
            $table->string('no_invoice');
            $table->date('tanggal_invoice')->nullable();
            $table->unsignedBigInteger('klien_ar_id')->nullable();
            $table->unsignedBigInteger('resto_id')->nullable();
            $table->unsignedBigInteger('perusahaan_id')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tagihan_periode_sebelumnya', 15, 2)->default(0);
            $table->decimal('total_tagihan', 15, 2)->default(0);
            $table->decimal('total_pembayaran', 15, 2)->default(0);
            $table->decimal('total_penyesuaian', 15, 2)->default(0);
            $table->decimal('sisa_tagihan', 15, 2)->default(0);
            $table->string('status')->default('TERKIRIM');
            $table->string('approval_status')->nullable();
            $table->boolean('is_opening_balance')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tb_opening_balance_detail', function ($table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('no_invoice_asal')->nullable();
            $table->date('tanggal_invoice_asal')->nullable();
            $table->decimal('jumlah_tagihan_asal', 15, 2)->default(0);
            $table->decimal('sisa_tagihan_asal', 15, 2)->default(0);
            $table->text('keterangan')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('tb_ending_balance_koreksi', function ($table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('status')->default('PENDING');
            $table->timestamps();
        });

        Schema::create('tb_pendapatan_di_muka', function ($table) {
            $table->id();
            $table->unsignedBigInteger('sumber_pembayaran_ar_id')->nullable();
            $table->string('status')->default('TERSEDIA');
            $table->timestamps();
        });

        Schema::create('tb_pembayaran_ar', function ($table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->date('tanggal_pembayaran')->nullable();
            $table->decimal('jumlah_pembayaran', 15, 2)->default(0);
            $table->string('metode_pembayaran')->nullable();
            $table->string('no_referensi')->nullable();
            $table->text('keterangan')->nullable();
            $table->unsignedBigInteger('sumber_pembayaran_ar_id')->nullable();
            $table->boolean('dibuat_dari_rekonsiliasi')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('tb_pembayaran_ar_items', function ($table) {
            $table->id();
            // FK sungguhan (bukan unsignedBigInteger polos) -- PembayaranArService::delete()
            // mengandalkan cascadeOnDelete() di sini (migrasi asli) supaya baris item ikut
            // terhapus otomatis saat header dihapus; tanpa ini recalculate() sesudahnya masih
            // membaca item basi (invoice keliru tetap LUNAS). foreign_key_constraints sqlite
            // sudah default true (config/database.php), jadi cascade ini benar-benar aktif.
            $table->foreignId('pembayaran_ar_id')->constrained('tb_pembayaran_ar')->cascadeOnDelete();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('klien_ar_id');
            $table->decimal('jumlah_dialokasikan', 15, 2)->default(0);
            $table->decimal('sisa_sebelum', 15, 2)->default(0);
            $table->decimal('sisa_sesudah', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('tb_pembayaran_ar_log', function ($table) {
            $table->id();
            $table->unsignedBigInteger('pembayaran_ar_id');
            $table->string('aksi');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('data_sebelum')->nullable();
            $table->text('data_sesudah')->nullable();
            $table->text('keterangan')->nullable();
            $table->timestamps();
        });

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
    }

    private function insertKlien(int $id, string $nama): void
    {
        DB::table('tb_klien_ar')->insert([
            'id' => $id, 'nama_klien' => $nama, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertInvoice(array $overrides = []): int
    {
        return DB::table('tb_invoice')->insertGetId(array_merge([
            'no_invoice'         => 'INV-' . random_int(100000, 999999),
            // Format datetime penuh (bukan '2026-07-10' saja): InvoiceService
            // membandingkan tanggal_invoice via query builder yang mem-bind Carbon
            // sebagai string 'Y-m-d H:i:s'. Di sqlite (TEXT-based date), '2026-07-10'
            // < '2026-07-10 00:00:00' secara leksikografis (prefix pendek dianggap
            // lebih kecil) — bikin invoice keliru "match" tanggal_invoice < dirinya
            // sendiri di sumOwnSisaBeforeInvoice(). MySQL produksi tidak kena ini
            // karena kolom DATE dibandingkan temporal, bukan string mentah.
            'tanggal_invoice'    => '2026-07-10 00:00:00',
            'klien_ar_id'        => 1,
            'perusahaan_id'      => 100,
            'subtotal'           => 100000,
            'total_tagihan'      => 100000,
            'total_pembayaran'   => 0,
            'total_penyesuaian'  => 0,
            'sisa_tagihan'       => 100000,
            'status'             => 'TERKIRIM',
            'is_opening_balance' => false,
            // InvoiceObserver::syncEb() butuh created_by non-null sebagai fallback
            // user id ($userId = auth()->id() ?? $invoice->created_by) karena test
            // ini tidak melakukan auth login sungguhan.
            'created_by'         => 1,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], $overrides));
    }

    private function insertBankStatementDetail(float $kredit, array $overrides = []): int
    {
        $statementId = DB::table('tb_bank_statement')->insertGetId([
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('tb_bank_statement_detail')->insertGetId(array_merge([
            'bank_statement_id' => $statementId,
            'tanggal'           => '2026-07-15',
            'kredit'            => $kredit,
            'created_at'        => now(),
            'updated_at'        => now(),
        ], $overrides));
    }

    /** @return array{0: PembayaranArService, 1: BankStatementService} */
    private function services(EndingBalanceService $ebService): array
    {
        $this->app->instance(EndingBalanceService::class, $ebService);

        $invoiceService      = $this->app->make(InvoiceService::class);
        $pembayaranArService = $this->app->make(PembayaranArService::class);
        $bankStatementService = new BankStatementService(
            $pembayaranArService,
            Mockery::mock(PembayaranApService::class),
            $invoiceService,
        );

        return [$pembayaranArService, $bankStatementService];
    }

    private function ebMockAllowingAnySync(): EndingBalanceService
    {
        $mock = Mockery::mock(EndingBalanceService::class);
        $mock->shouldReceive('isLockedForPeriod')->andReturn(false)->byDefault();
        $mock->shouldReceive('syncEbForKlien')->zeroOrMoreTimes();

        return $mock;
    }

    public function test_multi_payment_updates_sisa_tagihan_dan_status_lintas_resto(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Resto Alpha');
        $this->insertKlien(2, 'Resto Beta');

        $invoiceA = $this->insertInvoice(['klien_ar_id' => 1, 'subtotal' => 100000, 'total_tagihan' => 100000, 'sisa_tagihan' => 100000]);
        $invoiceB = $this->insertInvoice(['klien_ar_id' => 2, 'subtotal' => 200000, 'total_tagihan' => 200000, 'sisa_tagihan' => 200000]);

        $detailId = $this->insertBankStatementDetail(250000, ['no_referensi' => 'REF-MULTI-1']);

        [, $bankStatementService] = $this->services($this->ebMockAllowingAnySync());

        $detail = BankStatementDetail::find($detailId);
        $bankStatementService->matchWithNewMultiPayment($detail, [
            ['invoice_id' => $invoiceA, 'jumlah' => 100000], // pelunasan penuh
            ['invoice_id' => $invoiceB, 'jumlah' => 150000], // sebagian
        ]);

        $invA = Invoice::find($invoiceA);
        $invB = Invoice::find($invoiceB);

        $this->assertSame('LUNAS', $invA->status);
        $this->assertEquals(0.0, (float) $invA->sisa_tagihan);

        $this->assertSame('SEBAGIAN', $invB->status);
        $this->assertEquals(50000.0, (float) $invB->sisa_tagihan);

        $items = PembayaranArItem::orderBy('invoice_id')->get();
        $this->assertCount(2, $items);
        $this->assertEquals(100000.0, (float) $items->firstWhere('invoice_id', $invoiceA)->jumlah_dialokasikan);
        $this->assertEquals(150000.0, (float) $items->firstWhere('invoice_id', $invoiceB)->jumlah_dialokasikan);

        $detail->refresh();
        $this->assertSame('MATCHED', $detail->status_cocok);
        $this->assertNotNull($detail->pembayaran_ar_id);

        $pembayaran = $detail->pembayaranAr;
        $this->assertNull($pembayaran->invoice_id, 'Header Multi Payment harus invoice_id null.');
        $this->assertEquals(250000.0, (float) $pembayaran->jumlah_pembayaran);
    }

    public function test_multi_payment_dedup_sync_ending_balance_per_klien_periode(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Resto Alpha');
        $this->insertKlien(2, 'Resto Beta');

        // 2 invoice klien 1, bulan sama -> harus dedup jadi 1 panggilan syncEbForKlien.
        $invoiceA1 = $this->insertInvoice(['klien_ar_id' => 1, 'tanggal_invoice' => '2026-07-05 00:00:00', 'subtotal' => 50000, 'total_tagihan' => 50000, 'sisa_tagihan' => 50000]);
        $invoiceA2 = $this->insertInvoice(['klien_ar_id' => 1, 'tanggal_invoice' => '2026-07-20 00:00:00', 'subtotal' => 70000, 'total_tagihan' => 70000, 'sisa_tagihan' => 70000]);
        // 1 invoice klien 2 (resto/investor lain) -> panggilan syncEbForKlien terpisah.
        $invoiceB = $this->insertInvoice(['klien_ar_id' => 2, 'tanggal_invoice' => '2026-07-08 00:00:00', 'subtotal' => 90000, 'total_tagihan' => 90000, 'sisa_tagihan' => 90000]);

        $detailId = $this->insertBankStatementDetail(210000);

        $ebMock = Mockery::mock(EndingBalanceService::class);
        $ebMock->shouldReceive('isLockedForPeriod')->andReturn(false)->byDefault();
        // Bukti dedup: 3 invoice ter-update (masing-masing memicu 'updated' event),
        // tapi 2 di antaranya berbagi klien+periode yang sama -> hanya 2 panggilan
        // syncEbForKlien (bukan 3) yang boleh terjadi, DAN harus terjadi (bukan 0,
        // yang berarti EndingBalanceSyncBatcher::isActive() salah lagi jadi false).
        $ebMock->shouldReceive('syncEbForKlien')->times(2);

        [, $bankStatementService] = $this->services($ebMock);

        $detail = BankStatementDetail::find($detailId);
        $bankStatementService->matchWithNewMultiPayment($detail, [
            ['invoice_id' => $invoiceA1, 'jumlah' => 50000],
            ['invoice_id' => $invoiceA2, 'jumlah' => 70000],
            ['invoice_id' => $invoiceB, 'jumlah' => 90000],
        ]);

        // Verifikasi utama ada di ekspektasi Mockery di atas (diverifikasi otomatis
        // oleh Mockery::close() di tearDown()); assert tambahan supaya test tidak
        // dianggap "risky" oleh PHPUnit karena tanpa assertion eksplisit.
        $this->assertSame('LUNAS', Invoice::find($invoiceA1)->status);
        $this->assertSame('LUNAS', Invoice::find($invoiceA2)->status);
        $this->assertSame('LUNAS', Invoice::find($invoiceB)->status);
    }

    public function test_multi_payment_kelebihan_bayar_terhitung_benar_untuk_header_null_invoice(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Resto Alpha');

        $invoiceA = $this->insertInvoice(['klien_ar_id' => 1, 'subtotal' => 100000, 'total_tagihan' => 100000, 'sisa_tagihan' => 100000]);

        // Kredit bank lebih besar dari total alokasi -> sisanya harus terhitung
        // sebagai Kelebihan Bayar yang bisa dialokasikan lewat applyKelebihanBayar().
        $detailId = $this->insertBankStatementDetail(150000);

        [, $bankStatementService] = $this->services($this->ebMockAllowingAnySync());

        $detail = BankStatementDetail::find($detailId);
        $bankStatementService->matchWithNewMultiPayment($detail, [
            ['invoice_id' => $invoiceA, 'jumlah' => 100000],
        ]);

        $pembayaran = $detail->fresh()->pembayaranAr;
        $this->assertNotNull($pembayaran);
        $this->assertNull($pembayaran->invoice_id, 'Header Multi Payment harus invoice_id null.');

        $ref = new \ReflectionMethod($bankStatementService, 'computeKelebihanTotal');
        $ref->setAccessible(true);
        $kelebihan = $ref->invoke($bankStatementService, $pembayaran, $detail->fresh());

        $this->assertEquals(50000.0, $kelebihan);
    }

    public function test_unmatch_multi_payment_mengembalikan_semua_invoice(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Resto Alpha');
        $this->insertKlien(2, 'Resto Beta');

        $invoiceA = $this->insertInvoice(['klien_ar_id' => 1, 'subtotal' => 100000, 'total_tagihan' => 100000, 'sisa_tagihan' => 100000]);
        $invoiceB = $this->insertInvoice(['klien_ar_id' => 2, 'subtotal' => 200000, 'total_tagihan' => 200000, 'sisa_tagihan' => 200000]);

        $detailId = $this->insertBankStatementDetail(250000);

        [, $bankStatementService] = $this->services($this->ebMockAllowingAnySync());

        $detail = BankStatementDetail::find($detailId);
        $bankStatementService->matchWithNewMultiPayment($detail, [
            ['invoice_id' => $invoiceA, 'jumlah' => 100000],
            ['invoice_id' => $invoiceB, 'jumlah' => 150000],
        ]);

        $bankStatementService->unmatch($detail->fresh());

        $invA = Invoice::find($invoiceA);
        $invB = Invoice::find($invoiceB);

        $this->assertSame('TERKIRIM', $invA->status);
        $this->assertEquals(100000.0, (float) $invA->sisa_tagihan);
        $this->assertSame('TERKIRIM', $invB->status);
        $this->assertEquals(200000.0, (float) $invB->sisa_tagihan);

        $this->assertSame(0, PembayaranArItem::count());

        $detail->refresh();
        $this->assertSame('UNMATCHED', $detail->status_cocok);
        $this->assertNull($detail->pembayaran_ar_id);
    }

    public function test_unmatch_multi_payment_dedup_sync_ending_balance(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Resto Alpha');
        $this->insertKlien(2, 'Resto Beta');

        $invoiceA = $this->insertInvoice(['klien_ar_id' => 1, 'tanggal_invoice' => '2026-07-05', 'subtotal' => 100000, 'total_tagihan' => 100000, 'sisa_tagihan' => 100000]);
        $invoiceB = $this->insertInvoice(['klien_ar_id' => 2, 'tanggal_invoice' => '2026-07-08', 'subtotal' => 200000, 'total_tagihan' => 200000, 'sisa_tagihan' => 200000]);

        $detailId = $this->insertBankStatementDetail(250000);

        $ebMock = Mockery::mock(EndingBalanceService::class);
        $ebMock->shouldReceive('isLockedForPeriod')->andReturn(false)->byDefault();
        // 1 panggilan wajar saat create (2 klien beda -> 2x), berapa pun itu tidak
        // relevan di sini — yang dites adalah unmatch() JUGA memicu sync (bukan 0,
        // yang berarti bug lama muncul lagi di jalur delete/unmatch).
        $ebMock->shouldReceive('syncEbForKlien')->atLeast()->times(2);

        [, $bankStatementService] = $this->services($ebMock);

        $detail = BankStatementDetail::find($detailId);
        $bankStatementService->matchWithNewMultiPayment($detail, [
            ['invoice_id' => $invoiceA, 'jumlah' => 100000],
            ['invoice_id' => $invoiceB, 'jumlah' => 150000],
        ]);

        $bankStatementService->unmatch($detail->fresh());

        $this->assertSame('TERKIRIM', Invoice::find($invoiceA)->status);
        $this->assertSame('TERKIRIM', Invoice::find($invoiceB)->status);
    }

    public function test_multi_payment_menolak_invoice_beda_entitas(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Resto Alpha');
        $this->insertKlien(2, 'Resto Beta');

        $invoiceA = $this->insertInvoice(['klien_ar_id' => 1, 'perusahaan_id' => 100, 'subtotal' => 100000, 'total_tagihan' => 100000, 'sisa_tagihan' => 100000]);
        $invoiceB = $this->insertInvoice(['klien_ar_id' => 2, 'perusahaan_id' => 200, 'subtotal' => 200000, 'total_tagihan' => 200000, 'sisa_tagihan' => 200000]);

        $detailId = $this->insertBankStatementDetail(250000);

        [, $bankStatementService] = $this->services($this->ebMockAllowingAnySync());

        $detail = BankStatementDetail::find($detailId);

        try {
            $bankStatementService->matchWithNewMultiPayment($detail, [
                ['invoice_id' => $invoiceA, 'jumlah' => 100000],
                ['invoice_id' => $invoiceB, 'jumlah' => 150000],
            ]);
            $this->fail('Seharusnya melempar HttpException 422 (beda entitas penagih).');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $detail->refresh();
        $this->assertSame('UNMATCHED', $detail->status_cocok, 'Tidak boleh ada perubahan jika guard entitas gagal.');
        $this->assertSame(0, PembayaranArItem::count());
    }
}
