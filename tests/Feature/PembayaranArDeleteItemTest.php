<?php

namespace Tests\Feature;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceService;
use App\Domain\Finance\EndingBalance\Services\EndingBalanceSyncBatcher;
use App\Domain\Finance\PembayaranAr\Controllers\PembayaranArController;
use App\Domain\Finance\PembayaranAr\Services\PembayaranArService;
use App\Domain\Finance\PembayaranAr\Services\RiwayatPembayaranService;
use App\Domain\Notification\Services\FinanceNotificationService;
use App\Models\BankStatementDetail;
use App\Models\Invoice;
use App\Models\Karyawan;
use App\Models\PembayaranAr;
use App\Models\PembayaranArItem;
use App\Models\EndingBalanceKoreksi;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Regresi fitur "Hapus 1 alokasi dari Multi Payment" (2026-08-24): dulu
 * satu-satunya cara hapus pembayaran Multi Payment adalah
 * PembayaranArService::delete() yang selalu menghapus SELURUH header (cascade
 * ke semua PembayaranArItem), memaksa Admin/Manager/Supervisor membatalkan
 * seluruh Multi Payment hanya untuk membetulkan 1 invoice, dan sering
 * memblokir PIC AR total lewat authorizeDeleteOwnership() (mengecek SEMUA
 * invoice di header, bukan cuma yang relevan).
 *
 * deleteItem() menghapus 1 PembayaranArItem saja, menyisakan header & alokasi
 * invoice lain utuh — kecuali item yang dihapus adalah item TERAKHIR di
 * header, di mana perilakunya delegasi penuh ke delete() (setara full-delete,
 * termasuk unlink BankStatementDetail).
 *
 * Menghindari RefreshDatabase (lihat project_test_db_migrate_fresh_broken di
 * memory) — schema ad-hoc via Schema::create, pola sama seperti
 * PembayaranArMultiPaymentInvoiceUpdateTest.php.
 */
class PembayaranArDeleteItemTest extends TestCase
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
        DB::connection()->getPdo()->sqliteCreateFunction('GREATEST', fn ($a, $b) => max($a, $b), 2);

        Schema::create('tb_klien_ar', function ($table) {
            $table->id();
            $table->string('nama_klien');
            $table->unsignedBigInteger('karyawan_ar_id')->nullable();
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
            $table->string('status')->default('AKTIF');
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
    }

    private function insertKlien(int $id, string $nama, array $overrides = []): void
    {
        DB::table('tb_klien_ar')->insert(array_merge([
            'id' => $id, 'nama_klien' => $nama, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
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

    private function ebMockAllowingAnySync(): EndingBalanceService
    {
        $mock = Mockery::mock(EndingBalanceService::class);
        $mock->shouldReceive('isLockedForPeriod')->andReturn(false)->byDefault();
        $mock->shouldReceive('syncEbForKlien')->zeroOrMoreTimes();

        return $mock;
    }

    private function service(EndingBalanceService $ebService): PembayaranArService
    {
        $this->app->instance(EndingBalanceService::class, $ebService);

        return $this->app->make(PembayaranArService::class);
    }

    public function test_hapus_1_item_menyisakan_header_dan_alokasi_invoice_lain_utuh(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Resto Alpha');
        $this->insertKlien(2, 'Resto Beta');

        // klien_ar_id BERBEDA supaya invoice A & B tidak terhubung carryover
        // cascade (kalau 1 klien+bulan yang sama, sisa invoice A yang jadi
        // TERKIRIM lagi otomatis ikut mengalir ke tagihan_periode_sebelumnya
        // invoice B berikutnya -- itu perilaku carryover yang BENAR, bukan bug,
        // tapi test ini ingin membuktikan B independen dari A).
        $invoiceA = $this->insertInvoice(['klien_ar_id' => 1, 'subtotal' => 100000, 'total_tagihan' => 100000, 'sisa_tagihan' => 100000]);
        $invoiceB = $this->insertInvoice(['klien_ar_id' => 2, 'subtotal' => 200000, 'total_tagihan' => 200000, 'sisa_tagihan' => 200000]);

        $service = $this->service($this->ebMockAllowingAnySync());

        $pembayaran = $service->createMultiPayment([
            'tanggal_pembayaran'       => '2026-07-15',
            'metode_pembayaran'        => 'TRANSFER',
            'no_referensi'             => 'REF-DEL-1',
            'dibuat_dari_rekonsiliasi' => false,
            'alokasi'                  => [
                ['invoice_id' => $invoiceA, 'jumlah' => 100000],
                ['invoice_id' => $invoiceB, 'jumlah' => 150000],
            ],
        ]);

        $itemA = PembayaranArItem::where('invoice_id', $invoiceA)->firstOrFail();

        $service->deleteItem($pembayaran->fresh(), $itemA);

        // Invoice A kembali seperti sebelum dibayar.
        $invA = Invoice::find($invoiceA);
        $this->assertEquals(0.0, (float) $invA->total_pembayaran);
        $this->assertEquals(100000.0, (float) $invA->sisa_tagihan);
        $this->assertSame('TERKIRIM', $invA->status);

        // Invoice B & alokasinya SAMA SEKALI tidak terpengaruh.
        $invB = Invoice::find($invoiceB);
        $this->assertEquals(150000.0, (float) $invB->total_pembayaran);
        $this->assertEquals(50000.0, (float) $invB->sisa_tagihan);
        $this->assertSame('SEBAGIAN', $invB->status);
        $this->assertDatabaseCount('tb_pembayaran_ar_items', 1);
        $this->assertNotNull(PembayaranArItem::where('invoice_id', $invoiceB)->first());

        // Header tetap ada (bukan full-delete), jumlah_pembayaran didekrement.
        $header = PembayaranAr::find($pembayaran->id);
        $this->assertNotNull($header);
        $this->assertEquals(150000.0, (float) $header->jumlah_pembayaran);

        $this->assertDatabaseCount('tb_pembayaran_ar_items', 1);
        $itemA_gone = PembayaranArItem::find($itemA->id);
        $this->assertNull($itemA_gone);
    }

    public function test_hapus_item_terakhir_delegasi_ke_full_delete(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Resto Alpha');
        $this->insertKlien(2, 'Resto Beta');

        $invoiceA = $this->insertInvoice(['klien_ar_id' => 1, 'subtotal' => 100000, 'total_tagihan' => 100000, 'sisa_tagihan' => 100000]);
        $invoiceB = $this->insertInvoice(['klien_ar_id' => 2, 'subtotal' => 200000, 'total_tagihan' => 200000, 'sisa_tagihan' => 200000]);
        $detailId = $this->insertBankStatementDetail(250000, ['no_referensi' => 'REF-DEL-2', 'status_cocok' => 'MATCHED']);

        $service = $this->service($this->ebMockAllowingAnySync());

        $pembayaran = $service->createMultiPayment([
            'tanggal_pembayaran'       => '2026-07-15',
            'metode_pembayaran'        => 'TRANSFER',
            'no_referensi'             => 'REF-DEL-2',
            'dibuat_dari_rekonsiliasi' => true,
            'alokasi'                  => [
                ['invoice_id' => $invoiceA, 'jumlah' => 100000],
                ['invoice_id' => $invoiceB, 'jumlah' => 150000],
            ],
        ]);

        DB::table('tb_bank_statement_detail')->where('id', $detailId)->update(['pembayaran_ar_id' => $pembayaran->id]);

        $itemA = PembayaranArItem::where('invoice_id', $invoiceA)->firstOrFail();
        $itemB = PembayaranArItem::where('invoice_id', $invoiceB)->firstOrFail();

        // Hapus item A dulu (masih ada 1 sisa -> partial).
        $service->deleteItem($pembayaran->fresh(), $itemA);
        $this->assertNotNull(PembayaranAr::find($pembayaran->id), 'Header belum boleh hilang, masih ada 1 item.');

        // Hapus item B (item TERAKHIR) -> harus delegasi ke delete() penuh.
        $service->deleteItem($pembayaran->fresh(), $itemB->fresh());

        $this->assertNull(PembayaranAr::find($pembayaran->id), 'Header harus ikut terhapus saat item terakhir dihapus.');
        $this->assertDatabaseCount('tb_pembayaran_ar_items', 0);

        $invB = Invoice::find($invoiceB);
        $this->assertEquals(0.0, (float) $invB->total_pembayaran);
        $this->assertEquals(200000.0, (float) $invB->sisa_tagihan);

        $detail = BankStatementDetail::find($detailId);
        $this->assertSame('UNMATCHED', $detail->status_cocok);
        $this->assertNull($detail->pembayaran_ar_id);
    }

    public function test_hapus_item_ditolak_jika_invoice_punya_cn_dn_approved(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Resto Alpha');

        $invoiceA = $this->insertInvoice(['subtotal' => 100000, 'total_tagihan' => 100000, 'sisa_tagihan' => 100000]);
        $invoiceB = $this->insertInvoice(['subtotal' => 200000, 'total_tagihan' => 200000, 'sisa_tagihan' => 200000]);

        $service = $this->service($this->ebMockAllowingAnySync());

        $pembayaran = $service->createMultiPayment([
            'tanggal_pembayaran'       => '2026-07-15',
            'metode_pembayaran'        => 'TRANSFER',
            'no_referensi'             => 'REF-DEL-3',
            'dibuat_dari_rekonsiliasi' => false,
            'alokasi'                  => [
                ['invoice_id' => $invoiceA, 'jumlah' => 100000],
                ['invoice_id' => $invoiceB, 'jumlah' => 150000],
            ],
        ]);

        EndingBalanceKoreksi::create(['invoice_id' => $invoiceA, 'status' => 'APPROVED']);

        $itemA = PembayaranArItem::where('invoice_id', $invoiceA)->firstOrFail();

        try {
            $service->deleteItem($pembayaran->fresh(), $itemA);
            $this->fail('Seharusnya melempar HttpException 422 (CN/DN approved).');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // Tidak ada yang berubah — item A masih ada, invoice B tidak tersentuh.
        $this->assertNotNull(PembayaranArItem::find($itemA->id));
        $this->assertDatabaseCount('tb_pembayaran_ar_items', 2);
    }

    private function fakePicArUser(int $karyawanId, int $karyawanPerusahaanId): User
    {
        $karyawan = new Karyawan();
        $karyawan->forceFill(['id' => $karyawanId, 'perusahaan_id' => $karyawanPerusahaanId]);

        $user = Mockery::mock(User::class)->makePartial();
        // isArStaff() memanggil hasAnyRole(['AR']) lalu hasAnyRole(['ADMIN','MANAGER','SUPERVISOR'])
        // — Spatie HasRoles::hasAnyRole() menerima array, bukan hasRole() per-role.
        $user->shouldReceive('hasAnyRole')->andReturnUsing(
            fn($roles) => in_array('AR', (array) $roles, true)
        );
        $user->setRelation('karyawan', $karyawan);

        return $user;
    }

    private function controllerForAuthTest(PembayaranArService $service): PembayaranArController
    {
        return new PembayaranArController(
            $service,
            Mockery::mock(RiwayatPembayaranService::class),
            Mockery::mock(FinanceNotificationService::class),
        );
    }

    /**
     * Regresi bug lanjutan (2026-08-24): authorizeDeleteItemOwnership() dulu ikut
     * mewarisi guard perusahaan_id dari authorizeDeleteOwnership() lama — padahal
     * pola otorisasi AR di seluruh codebase (BankStatementController::
     * authorizeInvoiceOwnership()/authorizeKlienArOwnership()) sengaja HANYA
     * berbasis kepemilikan klien (karyawan_ar_id), bukan entitas perusahaan_id.
     * Akibatnya PIC AR yang employment record-nya beda entitas dari invoice
     * kliennya (skenario normal) selalu ditolak 403 walau karyawan_ar_id-nya benar.
     */
    public function test_authorize_delete_item_ownership_lolos_untuk_pic_ar_meski_beda_perusahaan(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Resto Alpha', ['karyawan_ar_id' => 7]);

        // Invoice dibilling entitas 999 -- SENGAJA beda dari perusahaan_id
        // karyawan PIC AR (111) di bawah, untuk membuktikan guard yang sudah
        // dihapus tidak lagi memblokir.
        $invoiceId = $this->insertInvoice(['klien_ar_id' => 1, 'perusahaan_id' => 999]);

        $service    = $this->service($this->ebMockAllowingAnySync());
        $pembayaran = $service->createMultiPayment([
            'tanggal_pembayaran'       => '2026-07-15',
            'metode_pembayaran'        => 'TRANSFER',
            'no_referensi'             => 'REF-DEL-AUTH-1',
            'dibuat_dari_rekonsiliasi' => false,
            'alokasi'                  => [
                ['invoice_id' => $invoiceId, 'jumlah' => 100000],
            ],
        ]);
        $item = PembayaranArItem::with('invoice.klienAr')->where('invoice_id', $invoiceId)->firstOrFail();

        $user       = $this->fakePicArUser(karyawanId: 7, karyawanPerusahaanId: 111);
        $controller = $this->controllerForAuthTest($service);

        $method = (new \ReflectionClass($controller))->getMethod('authorizeDeleteItemOwnership');
        $method->setAccessible(true);

        // Tidak boleh melempar exception -- karyawan_ar_id (7) cocok, meski
        // perusahaan_id invoice (999) beda dari perusahaan_id karyawan (111).
        $method->invoke($controller, $item, $user);
        $this->addToAssertionCount(1);
    }

    public function test_authorize_delete_item_ownership_tolak_pic_ar_yang_bukan_pemilik_klien(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Resto Alpha', ['karyawan_ar_id' => 7]);
        $invoiceId = $this->insertInvoice(['klien_ar_id' => 1, 'perusahaan_id' => 999]);

        $service    = $this->service($this->ebMockAllowingAnySync());
        $pembayaran = $service->createMultiPayment([
            'tanggal_pembayaran'       => '2026-07-15',
            'metode_pembayaran'        => 'TRANSFER',
            'no_referensi'             => 'REF-DEL-AUTH-2',
            'dibuat_dari_rekonsiliasi' => false,
            'alokasi'                  => [
                ['invoice_id' => $invoiceId, 'jumlah' => 100000],
            ],
        ]);
        $item = PembayaranArItem::with('invoice.klienAr')->where('invoice_id', $invoiceId)->firstOrFail();

        // PIC AR LAIN (karyawan id 8), BUKAN pemilik klien ini (klien dimiliki
        // karyawan_ar_id 7) -- harus tetap ditolak.
        $user       = $this->fakePicArUser(karyawanId: 8, karyawanPerusahaanId: 999);
        $controller = $this->controllerForAuthTest($service);

        $method = (new \ReflectionClass($controller))->getMethod('authorizeDeleteItemOwnership');
        $method->setAccessible(true);

        try {
            $method->invoke($controller, $item, $user);
            $this->fail('Seharusnya melempar HttpException 403 (bukan pemilik klien).');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
