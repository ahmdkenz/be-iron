<?php

namespace Tests\Feature;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceService;
use App\Domain\Finance\EndingBalance\Services\EndingBalanceSyncBatcher;
use App\Domain\Finance\Invoice\Services\InvoiceGroupProcessor;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\PembayaranAp\Services\PembayaranApService;
use App\Domain\Finance\PembayaranAr\Services\PembayaranArService;
use App\Domain\Finance\RekonsiliasiBankStatement\Services\BankStatementService;
use App\Models\BankStatementDetail;
use App\Models\Invoice;
use App\Models\PendapatanDiMuka;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * Regresi audit "Cocokkan Transaksi" (2026-08-24): invoice yang dibayar via
 * Multi Payment (1 kredit bank dialokasikan ke banyak invoice, header
 * PembayaranAr selalu invoice_id NULL) dulu tidak ke-cover oleh
 * InvoiceService::handleExcessPaymentAfterUpdate() — method itu hanya mengecek
 * relasi pembayarans() (invoice_id langsung), jadi kalau invoice tsb di-reimport
 * ("Proses Data Aman") dengan nominal lebih kecil dari yang sudah dibayar,
 * kelebihannya tidak pernah otomatis dikonversi jadi Pendapatan di Muka (PDM).
 * Ada 2 celah yang diperbaiki bersamaan:
 * 1) handleExcessPaymentAfterUpdate() sekarang juga menelusuri
 *    pembayaranArItems() untuk menemukan header Multi Payment.
 * 2) InvoiceGroupProcessor::updateInvoice() dulu skip total kalau subtotal baru
 *    persis 0 (guard `&& subtotal > 0`) — dihapus.
 * 3) PendapatanDiMukaService::store() dulu selalu derive invoice dari
 *    $pembayaran->invoice (null untuk header Multi Payment) — sekarang terima
 *    $invoiceContext eksplisit.
 *
 * Test ini memanggil InvoiceGroupProcessor::updateInvoice() (private, via
 * reflection) — method yang SAMA persis dipakai re-import "Proses Data Aman"
 * saat invoice existing di-SAFE_UPDATE — supaya regresi ini membuktikan jalur
 * nyata, bukan cuma unit InvoiceService secara terisolasi.
 *
 * Menghindari RefreshDatabase (lihat project_test_db_migrate_fresh_broken di
 * memory) — schema ad-hoc via Schema::create, pola sama seperti
 * PembayaranArMultiPaymentInvoiceUpdateTest & InvoiceGroupProcessorProsesDataTest.
 */
class InvoiceExcessPaymentAfterReimportTest extends TestCase
{
    private const TABLES = [
        'tb_pendapatan_di_muka',
        'tb_pembayaran_ar_items',
        'tb_pembayaran_ar_log',
        'tb_bank_statement_detail',
        'tb_bank_statement',
        'tb_pembayaran_ar',
        'tb_invoice_item',
        'tb_ending_balance',
        'tb_ending_balance_koreksi',
        'tb_opening_balance_detail',
        'tb_invoice',
        'tb_resto',
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
        // MySQL/produksi) di raw SQL — sqlite tidak punya fungsi ini bawaan.
        DB::connection()->getPdo()->sqliteCreateFunction('GREATEST', fn ($a, $b) => max($a, $b), 2);

        Schema::create('tb_resto', function ($table) {
            $table->id();
            $table->string('nama_resto');
            $table->unsignedBigInteger('investor_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tb_klien_ar', function ($table) {
            $table->id();
            $table->string('nama_klien');
            $table->unsignedBigInteger('resto_id')->nullable();
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

        Schema::create('tb_invoice_item', function ($table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('barang_id')->nullable();
            $table->string('kode_barang', 50)->nullable();
            $table->string('nama_barang');
            $table->decimal('qty', 10, 3)->default(0);
            $table->string('satuan', 20)->nullable();
            $table->decimal('harga_satuan', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->string('keterangan')->nullable();
            $table->string('no_invoice_resto')->nullable();
            $table->string('kode_resto')->nullable();
            $table->string('nama_resto')->nullable();
            $table->timestamps();
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

        Schema::create('tb_ending_balance', function ($table) {
            $table->id();
            $table->unsignedBigInteger('klien_ar_id');
            $table->date('periode_awal');
            $table->date('periode_akhir');
            $table->string('status')->default('DRAFT');
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

        Schema::create('tb_pendapatan_di_muka', function ($table) {
            $table->id();
            $table->unsignedBigInteger('sumber_pembayaran_ar_id');
            $table->unsignedBigInteger('bank_statement_detail_id');
            $table->unsignedBigInteger('investor_id')->nullable();
            $table->unsignedBigInteger('klien_ar_id');
            $table->decimal('jumlah', 15, 2);
            $table->date('tanggal_pencatatan');
            $table->text('keterangan')->nullable();
            $table->string('status')->default('AKTIF');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
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

    private function invoiceService(): InvoiceService
    {
        $ebMock = Mockery::mock(EndingBalanceService::class);
        $ebMock->shouldReceive('isLockedForPeriod')->andReturn(false)->byDefault();
        $ebMock->shouldReceive('syncEbForKlien')->zeroOrMoreTimes();
        $this->app->instance(EndingBalanceService::class, $ebMock);

        return $this->app->make(InvoiceService::class);
    }

    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        $m = (new \ReflectionClass($target))->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($target, ...$args);
    }

    public function test_multi_payment_excess_setelah_reimport_turun_otomatis_jadi_pdm(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Resto Alpha');

        $invoiceId = $this->insertInvoice(['subtotal' => 100000, 'total_tagihan' => 100000, 'sisa_tagihan' => 100000]);
        $detailId  = $this->insertBankStatementDetail(100000, ['no_referensi' => 'REF-EXCESS-1']);

        $invoiceService       = $this->invoiceService();
        $pembayaranArService  = $this->app->make(PembayaranArService::class);
        $bankStatementService = new BankStatementService(
            $pembayaranArService,
            Mockery::mock(PembayaranApService::class),
            $invoiceService,
        );

        $detail = BankStatementDetail::find($detailId);
        $bankStatementService->matchWithNewMultiPayment($detail, [
            ['invoice_id' => $invoiceId, 'jumlah' => 100000],
        ]);

        $invoice = Invoice::find($invoiceId);
        $this->assertSame('LUNAS', $invoice->status);

        // Simulasi re-import "Proses Data Aman": nominal invoice diedit turun
        // jadi 40000 (mis. sebagian baris item hilang dari file sumber baru).
        // updateInvoice() adalah method PERSIS yang dipanggil processGroup()
        // saat invoice existing berstatus SEBAGIAN/TERKIRIM/LUNAS-parsial masuk
        // SAFE_UPDATE.
        $groupProcessor = new InvoiceGroupProcessor($invoiceService);
        $this->invoke($groupProcessor, 'updateInvoice', $invoice, [
            ['nama_barang' => 'Barang A', 'qty' => 1, 'harga_satuan' => 40000],
        ], null);

        $invoice = $invoice->fresh();
        $this->assertEquals(40000.0, (float) $invoice->subtotal);
        $this->assertSame('LUNAS', $invoice->status, 'Overpaid tetap LUNAS.');
        $this->assertEquals(0.0, (float) $invoice->sisa_tagihan);
        $this->assertEquals(
            100000.0,
            (float) $invoice->total_pembayaran,
            'PembayaranArItem dari Multi Payment lama harus tetap utuh (tidak dihapus/diubah oleh edit nominal).'
        );

        $header = $detail->fresh()->pembayaranAr;
        $this->assertNotNull($header);
        $this->assertNull($header->invoice_id, 'Header Multi Payment tetap invoice_id null.');

        $pdm = PendapatanDiMuka::where('sumber_pembayaran_ar_id', $header->id)->first();
        $this->assertNotNull(
            $pdm,
            'PDM harus otomatis dibuat untuk kelebihan bayar invoice yang tersentuh Multi Payment — sebelum fix, handleExcessPaymentAfterUpdate() hanya cek pembayarans() (invoice_id langsung) sehingga header Multi Payment (invoice_id null) tidak pernah kelihatan.'
        );
        $this->assertEquals(60000.0, (float) $pdm->jumlah);
        $this->assertEquals($detailId, $pdm->bank_statement_detail_id);
        $this->assertEquals(1, $pdm->klien_ar_id);
    }

    public function test_excess_tetap_jadi_pdm_walau_subtotal_reimport_jadi_nol(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Resto Alpha');

        $invoiceId = $this->insertInvoice(['subtotal' => 100000, 'total_tagihan' => 100000, 'sisa_tagihan' => 100000]);
        $detailId  = $this->insertBankStatementDetail(100000, ['no_referensi' => 'REF-EXCESS-2']);

        $invoiceService       = $this->invoiceService();
        $pembayaranArService  = $this->app->make(PembayaranArService::class);
        $bankStatementService = new BankStatementService(
            $pembayaranArService,
            Mockery::mock(PembayaranApService::class),
            $invoiceService,
        );

        $detail = BankStatementDetail::find($detailId);
        $bankStatementService->matchWithNewMultiPayment($detail, [
            ['invoice_id' => $invoiceId, 'jumlah' => 100000],
        ]);

        $invoice = Invoice::find($invoiceId);

        // Semua baris item hilang dari file re-import -> subtotal baru persis 0.
        // Sebelum fix, guard `total_pembayaran > subtotal && subtotal > 0` di
        // InvoiceGroupProcessor::updateInvoice() melewati handleExcessPaymentAfterUpdate()
        // sama sekali untuk kasus ini.
        $groupProcessor = new InvoiceGroupProcessor($invoiceService);
        $this->invoke($groupProcessor, 'updateInvoice', $invoice, [], null);

        $invoice = $invoice->fresh();
        $this->assertEquals(0.0, (float) $invoice->subtotal);
        $this->assertSame('LUNAS', $invoice->status);
        $this->assertEquals(100000.0, (float) $invoice->total_pembayaran);

        $header = $detail->fresh()->pembayaranAr;
        $pdm    = PendapatanDiMuka::where('sumber_pembayaran_ar_id', $header->id)->first();

        $this->assertNotNull(
            $pdm,
            'Guard subtotal > 0 yang lama dulu membuat kasus subtotal=0 ini dilewati sama sekali — sekarang harus tetap membuat PDM.'
        );
        $this->assertEquals(100000.0, (float) $pdm->jumlah);
    }
}
