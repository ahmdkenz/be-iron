<?php

namespace Tests\Feature;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceService;
use App\Domain\Finance\EndingBalance\Services\EndingBalanceSyncBatcher;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * cascadeCarryoverToNext() (private, dipanggil via propagateCarryover() publik) dioptimasi
 * 2026-08-07: dari query "next invoice" + query sum + update + fresh() PER LANGKAH (root cause
 * "Proses Data Aman" Import Master Invoice masih lambat di production untuk import besar-banyak-
 * klien) menjadi preload sekali + jalan di memori. Test ini membuktikan HASIL AKHIR (bukan cuma
 * "tidak error") tetap identik dengan algoritma lama untuk skenario cascade berantai yang
 * menyelipkan Opening Balance di tengah DAN melewati batas bulan (kasus yang paling rawan salah
 * kalau preload-nya keliru ambil rentang tanggal).
 *
 * Menghindari RefreshDatabase (migrate:fresh rusak di project ini, lihat memory
 * project_test_db_migrate_fresh_broken) — schema ad-hoc via Schema::create, pola sama seperti
 * PembayaranArMultiPaymentInvoiceUpdateTest. EndingBalanceService di-mock supaya InvoiceObserver
 * (updated() -> syncEb() -> DB::afterCommit) tidak perlu skema tb_ending_balance.
 */
class InvoiceCascadeCarryoverTest extends TestCase
{
    private const TABLES = ['tb_invoice', 'tb_klien_ar'];

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
        // Hanya dipakai kalau ada jalur yang masih memanggil sumOwnSisaBeforeInvoice() (raw SQL
        // GREATEST() — fungsi MySQL, tidak ada bawaan di sqlite). cascadeCarryoverToNext() versi
        // baru tidak lagi lewat sini, tapi didaftarkan tetap supaya test ini juga valid dijalankan
        // terhadap versi sebelum optimasi (lihat catatan verifikasi A/B di riwayat sesi).
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
            'klien_ar_id'        => 1,
            'perusahaan_id'      => 100,
            'is_opening_balance' => false,
            'created_by'         => 1,
            'created_at'         => now(),
            'updated_at'         => now(),
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

    public function test_cascade_berantai_lintas_bulan_dengan_opening_balance_di_tengah(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Klien Uji');

        // A: titik mulai cascade — sudah benar, tidak disentuh cascade (cascade hanya
        // menyentuh invoice SESUDAH A).
        $idA = $this->insertInvoice([
            'tanggal_invoice' => '2026-07-05 00:00:00',
            'subtotal' => 100000, 'total_pembayaran' => 30000, 'total_penyesuaian' => 0,
            'tagihan_periode_sebelumnya' => 0, 'total_tagihan' => 100000, 'sisa_tagihan' => 70000,
            'status' => 'SEBAGIAN',
        ]);

        // B: bulan sama dengan A (Juli) — carryover-nya SALAH (999), harus dikoreksi jadi
        // sisa A (100000-30000=70000).
        $idB = $this->insertInvoice([
            'tanggal_invoice' => '2026-07-20 00:00:00',
            'subtotal' => 50000, 'total_pembayaran' => 0, 'total_penyesuaian' => 0,
            'tagihan_periode_sebelumnya' => 999, 'total_tagihan' => 50999, 'sisa_tagihan' => 50999,
            'status' => 'TERKIRIM',
        ]);

        // OB: di antara B (Juli) dan C (Agustus) — tagihan_periode_sebelumnya SALAH (12345),
        // harus direset ke 0 (OB tidak boleh mewarisi carryover invoice reguler).
        $idOb = $this->insertInvoice([
            'tanggal_invoice' => '2026-08-01 00:00:00',
            'is_opening_balance' => true,
            'subtotal' => 20000, 'total_pembayaran' => 5000, 'total_penyesuaian' => 0,
            'tagihan_periode_sebelumnya' => 12345, 'total_tagihan' => 32345, 'sisa_tagihan' => 27345,
            'status' => 'SEBAGIAN',
        ]);

        // C: bulan Agustus (beda bulan dari A/B) — carryover SALAH (999). Harus jadi 0 karena
        // TIDAK ADA invoice reguler lain di Agustus sebelum C (OB dikecualikan dari sum, dan B
        // ada di Juli — di luar batas awal bulan Agustus). Ini kasus kunci yang membuktikan
        // preload TIDAK salah menganggap B ikut ke perhitungan C hanya karena B "sebelum" C.
        $idC = $this->insertInvoice([
            'tanggal_invoice' => '2026-08-15 00:00:00',
            'subtotal' => 80000, 'total_pembayaran' => 20000, 'total_penyesuaian' => 0,
            'tagihan_periode_sebelumnya' => 999, 'total_tagihan' => 80999, 'sisa_tagihan' => 60999,
            'status' => 'SEBAGIAN',
        ]);

        // D: Agustus juga, sesudah C — carryover harus = sisa C (80000-20000=60000). Membuktikan
        // status C yang BARU SAJA berubah jadi SEBAGIAN (dari nilai awal manapun) ikut terbaca
        // benar oleh langkah D dalam pemanggilan cascade yang SAMA (bukan snapshot beku).
        $idD = $this->insertInvoice([
            'tanggal_invoice' => '2026-08-20 00:00:00',
            'subtotal' => 40000, 'total_pembayaran' => 0, 'total_penyesuaian' => 0,
            'tagihan_periode_sebelumnya' => 0, 'total_tagihan' => 40000, 'sisa_tagihan' => 40000,
            'status' => 'TERKIRIM',
        ]);

        $service = $this->invoiceService();
        $service->propagateCarryover(Invoice::find($idA));

        $invB = Invoice::find($idB);
        $this->assertEquals(70000.0, (float) $invB->tagihan_periode_sebelumnya);
        $this->assertEquals(120000.0, (float) $invB->total_tagihan);
        $this->assertEquals(120000.0, (float) $invB->sisa_tagihan);
        $this->assertSame('TERKIRIM', $invB->status);

        $invOb = Invoice::find($idOb);
        $this->assertEquals(0.0, (float) $invOb->tagihan_periode_sebelumnya);
        $this->assertEquals(20000.0, (float) $invOb->total_tagihan);
        $this->assertEquals(15000.0, (float) $invOb->sisa_tagihan);
        $this->assertSame('SEBAGIAN', $invOb->status);

        $invC = Invoice::find($idC);
        $this->assertEquals(0.0, (float) $invC->tagihan_periode_sebelumnya);
        $this->assertEquals(80000.0, (float) $invC->total_tagihan);
        $this->assertEquals(60000.0, (float) $invC->sisa_tagihan);
        $this->assertSame('SEBAGIAN', $invC->status);

        $invD = Invoice::find($idD);
        $this->assertEquals(60000.0, (float) $invD->tagihan_periode_sebelumnya);
        $this->assertEquals(100000.0, (float) $invD->total_tagihan);
        $this->assertEquals(100000.0, (float) $invD->sisa_tagihan);
        $this->assertSame('TERKIRIM', $invD->status);

        // A sendiri tidak boleh berubah (bukan bagian yang di-cascade, cuma titik mulai).
        $invA = Invoice::find($idA);
        $this->assertEquals(0.0, (float) $invA->tagihan_periode_sebelumnya);
        $this->assertEquals(70000.0, (float) $invA->sisa_tagihan);
    }

    public function test_cascade_berhenti_lebih_awal_saat_carryover_sudah_benar(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Klien Uji');

        $idA = $this->insertInvoice([
            'tanggal_invoice' => '2026-07-05 00:00:00',
            'subtotal' => 100000, 'total_pembayaran' => 100000, 'total_penyesuaian' => 0,
            'tagihan_periode_sebelumnya' => 0, 'total_tagihan' => 100000, 'sisa_tagihan' => 0,
            'status' => 'LUNAS',
        ]);

        // B: carryover SUDAH benar (0, karena A LUNAS -> sisa A = 0) -> cascade harus BERHENTI
        // di sini tanpa menyentuh C sama sekali (replikasi exact perilaku early-return lama).
        $idB = $this->insertInvoice([
            'tanggal_invoice' => '2026-07-20 00:00:00',
            'subtotal' => 50000, 'total_pembayaran' => 0, 'total_penyesuaian' => 0,
            'tagihan_periode_sebelumnya' => 0, 'total_tagihan' => 50000, 'sisa_tagihan' => 50000,
            'status' => 'TERKIRIM',
        ]);

        // C: carryover sengaja SALAH (999) — untuk membuktikan cascade BENAR-BENAR berhenti di B
        // (early return) dan tidak "menerobos" sampai ke C walau datanya salah.
        $idC = $this->insertInvoice([
            'tanggal_invoice' => '2026-07-25 00:00:00',
            'subtotal' => 30000, 'total_pembayaran' => 0, 'total_penyesuaian' => 0,
            'tagihan_periode_sebelumnya' => 999, 'total_tagihan' => 30999, 'sisa_tagihan' => 30999,
            'status' => 'TERKIRIM',
        ]);

        $service = $this->invoiceService();
        $service->propagateCarryover(Invoice::find($idA));

        $invB = Invoice::find($idB);
        $this->assertEquals(0.0, (float) $invB->tagihan_periode_sebelumnya);
        $this->assertEquals(50000.0, (float) $invB->total_tagihan);

        $invC = Invoice::find($idC);
        $this->assertEquals(999.0, (float) $invC->tagihan_periode_sebelumnya, 'C tidak boleh tersentuh — cascade berhenti di B.');
    }
}
