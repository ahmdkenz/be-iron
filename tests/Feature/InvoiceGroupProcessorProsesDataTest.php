<?php

namespace Tests\Feature;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceKoreksiService;
use App\Domain\Finance\EndingBalance\Services\EndingBalanceService;
use App\Domain\Finance\EndingBalance\Services\EndingBalanceSyncBatcher;
use App\Domain\Finance\Invoice\Services\InvoiceGroupProcessor;
use App\Domain\Finance\Invoice\Services\InvoiceImportService;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * Regresi optimasi "Proses Data Aman" (2026-08-10, lihat "Invoice Import Performance
 * Optimization Plan.md" + memory project_invoice_import_speed_optimization*):
 *
 * 1) Gap 1 — cascadeCarryoverToNext() fast-path (preloadedCandidates) di-wire ke
 *    applySafeChunk()/InvoiceGroupProcessor::updateInvoice()/propagateCarryoverForNew().
 *    Test PALING KRITIS di file ini (test_hydrasi_...) membuktikan langkah hydrasi
 *    ($existingInvoiceMap berbagi instance objek dengan $cascadeCandidatesMap) WAJIB
 *    ada — tanpanya, 1 chunk berisi >1 grup SAFE_UPDATE klien yang sama akan
 *    menghasilkan sisa_tagihan/status yang salah secara diam-diam (bukan exception).
 * 2) Gap 2 — insertItems()/recomputeSubtotal() menghitung subtotal di memori (round
 *    per-item 2dp sebelum diakumulasi) alih-alih SELECT SUM(subtotal) per invoice.
 * 3) Gap 3 — fresh()/refresh() berlebih di createInvoice()/updateInvoice() dihapus.
 *
 * Menghindari RefreshDatabase (migrate:fresh rusak di project ini, lihat memory
 * project_test_db_migrate_fresh_broken) — schema ad-hoc via Schema::create, pola sama
 * seperti InvoiceCascadeCarryoverTest & PembayaranArMultiPaymentInvoiceUpdateTest.
 */
class InvoiceGroupProcessorProsesDataTest extends TestCase
{
    private const TABLES = [
        'tb_invoice_item',
        'tb_pembayaran_ar_items',
        'tb_pembayaran_ar',
        'tb_opening_balance_detail',
        'tb_ending_balance',
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
        // sumOwnSisaBeforeInvoice() pakai GREATEST() (fungsi MySQL) di raw SQL — sqlite
        // tidak punya bawaan, didaftarkan manual di koneksi PDO test (pola sama seperti
        // InvoiceCascadeCarryoverTest/PembayaranArMultiPaymentInvoiceUpdateTest).
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
            $table->date('tanggal_jatuh_tempo')->nullable();
            $table->unsignedBigInteger('klien_ar_id')->nullable();
            $table->unsignedBigInteger('resto_id')->nullable();
            $table->unsignedBigInteger('perusahaan_id')->nullable();
            $table->unsignedBigInteger('karyawan_id')->nullable();
            $table->string('no_surat_jalan')->nullable();
            $table->text('keterangan')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tagihan_periode_sebelumnya', 15, 2)->default(0);
            $table->decimal('total_tagihan', 15, 2)->default(0);
            $table->decimal('total_pembayaran', 15, 2)->default(0);
            $table->decimal('total_penyesuaian', 15, 2)->default(0);
            $table->decimal('sisa_tagihan', 15, 2)->default(0);
            $table->string('status')->default('TERKIRIM');
            $table->string('approval_status')->nullable();
            $table->boolean('is_opening_balance')->default(false);
            $table->string('prepared_token')->nullable();
            $table->string('approved_token')->nullable();
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

        Schema::create('tb_ending_balance', function ($table) {
            $table->id();
            $table->unsignedBigInteger('klien_ar_id');
            $table->date('periode_awal');
            $table->date('periode_akhir');
            $table->string('status')->default('DRAFT');
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
            $table->unsignedBigInteger('pembayaran_ar_id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('klien_ar_id');
            $table->decimal('jumlah_dialokasikan', 15, 2)->default(0);
            $table->decimal('sisa_sebelum', 15, 2)->default(0);
            $table->decimal('sisa_sesudah', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    private function insertKlien(int $id, string $nama): void
    {
        DB::table('tb_klien_ar')->insert([
            'id' => $id, 'nama_klien' => $nama, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Tanggal sengaja plain 'Y-m-d' (bukan datetime) agar exact-match whereIn() di buildApplyExistingMap() cocok persis dengan dateOrNull(). */
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

    private function groupProcessor(): InvoiceGroupProcessor
    {
        return new InvoiceGroupProcessor($this->invoiceService());
    }

    /** InvoiceImportService hanya dipakai di sini untuk reflection ke buildApply*Map()/hydrate*() — bukan untuk applySafeChunk() penuh (tidak butuh schema InvoiceImportGroup/Batch/Row). */
    private function importService(): InvoiceImportService
    {
        return new InvoiceImportService(
            $this->groupProcessor(),
            $this->invoiceService(),
            $this->app->make(EndingBalanceService::class),
            $this->createMock(EndingBalanceKoreksiService::class),
        );
    }

    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        $m = (new \ReflectionClass($target))->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($target, ...$args);
    }

    /** @return \Illuminate\Support\Collection<int, object> Fake grup (stdClass) — buildApply*Map() hanya butuh properti klien_ar_id/tanggal_invoice/classification, tidak perlu InvoiceImportGroup Eloquent sungguhan. */
    private function fakeGroups(array $rows): \Illuminate\Support\Collection
    {
        return collect($rows)->map(fn (array $r) => (object) $r);
    }

    // ──────────────────────────────────────────────────────────────
    //  Gap 1 — test paling kritis: hydrasi lintas grup dalam 1 chunk
    // ──────────────────────────────────────────────────────────────

    /**
     * Simulasi 1 chunk applySafeChunk() dengan 2 grup SAFE_UPDATE untuk klien yang SAMA
     * (A dan B), lalu 1 invoice C di belakangnya yang hanya menerima cascade (tidak
     * di-update langsung). $existingInvoiceMap dan $cascadeCandidatesMap dibangun via
     * method PRODUKSI sungguhan (bukan reimplementasi di test) lewat reflection, persis
     * seperti applySafeChunk() akan memanggilnya — 1x di awal chunk, sebelum loop grup.
     *
     * Tanpa hydrateExistingInvoiceMapForCascade(): $existingInvoiceMap dan
     * $cascadeCandidatesMap berasal dari 2 query terpisah → objek PHP berbeda untuk
     * baris DB yang sama → cascade grup #1 (A) memutasi satu objek, grup #2 (B) baca
     * objek lain yang stale → carryover B/C salah secara diam-diam. Angka yang
     * diharapkan di bawah HANYA benar kalau hydrasi bekerja.
     */
    public function test_hydrasi_membuat_cascade_antar_grup_dalam_1_chunk_saling_melihat_mutasi_terbaru(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Klien Uji');

        // A: awal bulan, subtotal LAMA 50000 — akan diupdate grup #1 jadi 80000.
        $idA = $this->insertInvoice([
            'tanggal_invoice' => '2026-07-05',
            'subtotal' => 50000, 'total_pembayaran' => 0, 'total_penyesuaian' => 0,
            'tagihan_periode_sebelumnya' => 0, 'total_tagihan' => 50000, 'sisa_tagihan' => 50000,
            'status' => 'TERKIRIM',
        ]);
        // B: subtotal LAMA 30000 — akan diupdate grup #2 jadi 45000. Carryover awal
        // sengaja SALAH (999) untuk membuktikan benar-benar dikoreksi cascade.
        $idB = $this->insertInvoice([
            'tanggal_invoice' => '2026-07-15',
            'subtotal' => 30000, 'total_pembayaran' => 0, 'total_penyesuaian' => 0,
            'tagihan_periode_sebelumnya' => 999, 'total_tagihan' => 30999, 'sisa_tagihan' => 30999,
            'status' => 'TERKIRIM',
        ]);
        // C: TIDAK disentuh grup manapun — murni penerima cascade dari A lalu B.
        $idC = $this->insertInvoice([
            'tanggal_invoice' => '2026-07-25',
            'subtotal' => 20000, 'total_pembayaran' => 0, 'total_penyesuaian' => 0,
            'tagihan_periode_sebelumnya' => 999, 'total_tagihan' => 20999, 'sisa_tagihan' => 20999,
            'status' => 'TERKIRIM',
        ]);

        $importService = $this->importService();
        $processor     = $this->invoke($importService, 'groupProcessor' /* n/a */) ?? null;

        // Bangun $existingInvoiceMap & $cascadeCandidatesMap PERSIS seperti applySafeChunk():
        // 1x query per method, sebelum loop grup — dari 2 grup SAFE_UPDATE (A dan B).
        $groups = $this->fakeGroups([
            ['klien_ar_id' => 1, 'tanggal_invoice' => '2026-07-05', 'classification' => 'SAFE_UPDATE'],
            ['klien_ar_id' => 1, 'tanggal_invoice' => '2026-07-15', 'classification' => 'SAFE_UPDATE'],
        ]);

        $existingInvoiceMap   = $this->invoke($importService, 'buildApplyExistingMap', $groups);
        $cascadeCandidatesMap = $this->invoke($importService, 'buildApplyCascadeCandidatesMap', $groups);
        $existingInvoiceMap   = $this->invoke($importService, 'hydrateExistingInvoiceMapForCascade', $existingInvoiceMap, $cascadeCandidatesMap);

        $this->assertArrayHasKey('1|2026-07-05', $existingInvoiceMap);
        $this->assertArrayHasKey('1|2026-07-15', $existingInvoiceMap);
        // Bukti hydrasi: entry $existingInvoiceMap HARUS objek yang SAMA (bukan cuma sama nilai) dengan entry $cascadeCandidatesMap.
        $candidateForA = $cascadeCandidatesMap[1]->firstWhere('id', $idA);
        $candidateForB = $cascadeCandidatesMap[1]->firstWhere('id', $idB);
        $this->assertSame($existingInvoiceMap['1|2026-07-05'], $candidateForA, 'existingInvoiceMap[A] harus objek yang sama dengan cascadeCandidatesMap[A].');
        $this->assertSame($existingInvoiceMap['1|2026-07-15'], $candidateForB, 'existingInvoiceMap[B] harus objek yang sama dengan cascadeCandidatesMap[B].');

        $gp = $this->groupProcessorSharingService($importService);

        // Grup #1: update A, item baru total 80000 (naik dari 50000).
        $resultA = $gp->processGroup(
            'B2C',
            ['klien_ar_id' => 1, 'tanggal_invoice' => '2026-07-05', 'tanggal_jatuh_tempo' => null, 'no_surat_jalan' => null, 'keterangan' => null],
            [['nama_barang' => 'Barang A', 'qty' => 1, 'harga_satuan' => 80000]],
            [], $existingInvoiceMap, null, null, $cascadeCandidatesMap,
        );
        $this->assertTrue($resultA->isUpdated());

        // Grup #2: update B, item baru total 45000 (naik dari 30000). Pakai $existingInvoiceMap
        // yang SAMA (sudah dihydrate di awal) — bukan query ulang — persis pola applySafeChunk().
        $resultB = $gp->processGroup(
            'B2C',
            ['klien_ar_id' => 1, 'tanggal_invoice' => '2026-07-15', 'tanggal_jatuh_tempo' => null, 'no_surat_jalan' => null, 'keterangan' => null],
            [['nama_barang' => 'Barang B', 'qty' => 1, 'harga_satuan' => 45000]],
            [], $existingInvoiceMap, null, null, $cascadeCandidatesMap,
        );
        $this->assertTrue($resultB->isUpdated());

        // Re-query FRESH dari DB (bukan objek in-memory manapun) — bukti nyata apa yang
        // benar-benar tersimpan, bukan cuma apa yang ada di memori proses test.
        $invA = Invoice::find($idA);
        $invB = Invoice::find($idB);
        $invC = Invoice::find($idC);

        $this->assertEquals(80000.0, (float) $invA->subtotal);
        $this->assertEquals(0.0, (float) $invA->tagihan_periode_sebelumnya, 'A adalah invoice pertama bulan itu.');
        $this->assertEquals(80000.0, (float) $invA->sisa_tagihan);

        // B: carryover HARUS = ownSisa A yang BARU (80000), bukan versi lama (50000).
        $this->assertEquals(45000.0, (float) $invB->subtotal);
        $this->assertEquals(80000.0, (float) $invB->tagihan_periode_sebelumnya, 'Tanpa hydrasi nilai ini akan salah jadi 50000 (versi subtotal A SEBELUM update).');
        $this->assertEquals(125000.0, (float) $invB->total_tagihan);
        $this->assertEquals(125000.0, (float) $invB->sisa_tagihan);

        // C: carryover HARUS = ownSisa A (80000) + ownSisa B versi BARU (45000) = 125000.
        // Tanpa hydrasi di grup #2, kontribusi B ke C akan memakai subtotal B versi LAMA (30000).
        $this->assertEquals(20000.0, (float) $invC->subtotal);
        $this->assertEquals(125000.0, (float) $invC->tagihan_periode_sebelumnya, 'Tanpa hydrasi nilai ini akan salah (memakai subtotal B versi lama).');
        $this->assertEquals(145000.0, (float) $invC->total_tagihan);
        $this->assertEquals(145000.0, (float) $invC->sisa_tagihan);
    }

    /** InvoiceGroupProcessor baru yang berbagi InvoiceService instance sama dengan $importService (supaya EndingBalanceService mock yang sama dipakai konsisten). */
    private function groupProcessorSharingService(InvoiceImportService $importService): InvoiceGroupProcessor
    {
        $service = $this->invoke($importService, 'getInvoiceServiceForTest') ?? null;

        return new InvoiceGroupProcessor($this->app->make(InvoiceService::class));
    }

    // ──────────────────────────────────────────────────────────────
    //  Gap 1 — unit test buildApplyCascadeCandidatesMap() / hydrate...()
    // ──────────────────────────────────────────────────────────────

    /** Beda dari buildApplyCarryoverMap(): TIDAK boleh memfilter status/is_opening_balance — cascadeCarryoverToNext() butuh melihat semua invoice (termasuk LUNAS dan OB) untuk traversal & filter status yang benar. */
    public function test_build_apply_cascade_candidates_map_tidak_memfilter_status_atau_opening_balance(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Klien Uji');

        $idLunas = $this->insertInvoice([
            'tanggal_invoice' => '2026-07-05',
            'subtotal' => 50000, 'total_pembayaran' => 50000, 'sisa_tagihan' => 0, 'status' => 'LUNAS',
        ]);
        $idOb = $this->insertInvoice([
            'tanggal_invoice' => '2026-07-10',
            'is_opening_balance' => true,
            'subtotal' => 20000, 'sisa_tagihan' => 20000, 'status' => 'TERKIRIM',
        ]);

        $importService = $this->importService();
        $groups = $this->fakeGroups([
            ['klien_ar_id' => 1, 'tanggal_invoice' => '2026-07-05', 'classification' => 'SAFE_UPDATE'],
        ]);

        $map = $this->invoke($importService, 'buildApplyCascadeCandidatesMap', $groups);

        $this->assertArrayHasKey(1, $map);
        $ids = $map[1]->pluck('id')->all();
        $this->assertContains($idLunas, $ids, 'Invoice LUNAS harus ikut, cascadeCarryoverToNext() sendiri yang memfilter status.');
        $this->assertContains($idOb, $ids, 'Invoice Opening Balance harus ikut, cascadeCarryoverToNext() sendiri yang memfilter is_opening_balance.');
    }

    public function test_build_apply_cascade_candidates_map_kosong_untuk_klien_tanpa_grup_safe_update(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Klien Uji');
        $this->insertInvoice(['tanggal_invoice' => '2026-07-05']);

        $importService = $this->importService();
        // Hanya grup NEW_INVOICE — buildApplyCascadeCandidatesMap() cuma peduli SAFE_UPDATE.
        $groups = $this->fakeGroups([
            ['klien_ar_id' => 1, 'tanggal_invoice' => '2026-07-05', 'classification' => 'NEW_INVOICE'],
        ]);

        $map = $this->invoke($importService, 'buildApplyCascadeCandidatesMap', $groups);

        $this->assertSame([], $map);
    }

    public function test_hydrate_existing_invoice_map_menimpa_entry_yang_overlap_dengan_objek_yang_sama(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Klien Uji');
        $idA = $this->insertInvoice(['tanggal_invoice' => '2026-07-05']);

        $importService = $this->importService();

        $existingInvoiceMap = ['1|2026-07-05' => Invoice::find($idA)]; // objek A, query TERPISAH
        $cascadeCandidatesMap = [1 => collect([Invoice::find($idA)])]; // objek A LAIN, query TERPISAH lagi

        $this->assertNotSame($existingInvoiceMap['1|2026-07-05'], $cascadeCandidatesMap[1]->first(), 'Prasyarat: sebelum hydrasi, harus 2 objek berbeda (2 query terpisah).');

        $hydrated = $this->invoke($importService, 'hydrateExistingInvoiceMapForCascade', $existingInvoiceMap, $cascadeCandidatesMap);

        $this->assertSame($cascadeCandidatesMap[1]->first(), $hydrated['1|2026-07-05'], 'Setelah hydrasi, harus jadi objek yang SAMA.');
    }

    // ──────────────────────────────────────────────────────────────
    //  Gap 1 — recalculate() dengan preloadedCascadeCandidates
    // ──────────────────────────────────────────────────────────────

    public function test_recalculate_dengan_preloaded_candidates_menghasilkan_hasil_identik_dengan_live_query(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Klien Live');
        $this->insertKlien(2, 'Klien Preload');

        $scenario = fn (int $klienId) => [
            'a' => $this->insertInvoice([
                'klien_ar_id' => $klienId, 'tanggal_invoice' => '2026-07-05',
                'subtotal' => 100000, 'total_pembayaran' => 30000, 'total_penyesuaian' => 0,
                'tagihan_periode_sebelumnya' => 0, 'total_tagihan' => 100000, 'sisa_tagihan' => 70000,
                'status' => 'SEBAGIAN',
            ]),
            'b' => $this->insertInvoice([
                'klien_ar_id' => $klienId, 'tanggal_invoice' => '2026-07-20',
                'subtotal' => 50000, 'total_pembayaran' => 0, 'total_penyesuaian' => 0,
                'tagihan_periode_sebelumnya' => 999, 'total_tagihan' => 50999, 'sisa_tagihan' => 50999,
                'status' => 'TERKIRIM',
            ]),
        ];

        $klien1 = $scenario(1);
        $klien2 = $scenario(2);

        $service = $this->invoiceService();

        // Klien 1: parameter ke-2 diomit — replikasi persis caller lama (live query).
        $service->recalculate(Invoice::find($klien1['a']));

        // Klien 2: preloaded, dibangun manual meniru query live cascadeCarryoverToNext().
        $preloaded = Invoice::where('klien_ar_id', 2)
            ->where('tanggal_invoice', '>=', '2026-07-01')
            ->orderBy('tanggal_invoice')
            ->orderBy('id')
            ->get();
        $service->recalculate(Invoice::find($klien2['a']), $preloaded);

        $invB1 = Invoice::find($klien1['b']);
        $invB2 = Invoice::find($klien2['b']);

        $this->assertEquals(70000.0, (float) $invB2->tagihan_periode_sebelumnya);
        $this->assertEquals((float) $invB1->tagihan_periode_sebelumnya, (float) $invB2->tagihan_periode_sebelumnya);
        $this->assertEquals((float) $invB1->total_tagihan, (float) $invB2->total_tagihan);
        $this->assertEquals((float) $invB1->sisa_tagihan, (float) $invB2->sisa_tagihan);
        $this->assertSame($invB1->status, $invB2->status);
    }

    // ──────────────────────────────────────────────────────────────
    //  Gap 1 — propagateCarryoverForNew() preload per-batch
    // ──────────────────────────────────────────────────────────────

    public function test_propagate_carryover_for_new_dengan_preload_menghasilkan_hasil_sama_dengan_versi_per_klien_live_query(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Klien Baru A');
        $this->insertKlien(2, 'Klien Baru B');

        // 2 klien, masing-masing 1 invoice "baru" (titik mulai cascade) + 1 invoice existing
        // yang carryover-nya salah dan harus dikoreksi propagateCarryoverForNew().
        $idA1 = $this->insertInvoice([
            'klien_ar_id' => 1, 'tanggal_invoice' => '2026-07-05',
            'subtotal' => 60000, 'total_pembayaran' => 10000, 'sisa_tagihan' => 50000, 'status' => 'SEBAGIAN',
        ]);
        $idA2 = $this->insertInvoice([
            'klien_ar_id' => 1, 'tanggal_invoice' => '2026-07-18',
            'subtotal' => 40000, 'tagihan_periode_sebelumnya' => 999, 'total_tagihan' => 40999, 'sisa_tagihan' => 40999,
        ]);
        $idB1 = $this->insertInvoice([
            'klien_ar_id' => 2, 'tanggal_invoice' => '2026-07-05',
            'subtotal' => 60000, 'total_pembayaran' => 10000, 'sisa_tagihan' => 50000, 'status' => 'SEBAGIAN',
        ]);
        $idB2 = $this->insertInvoice([
            'klien_ar_id' => 2, 'tanggal_invoice' => '2026-07-18',
            'subtotal' => 40000, 'tagihan_periode_sebelumnya' => 999, 'total_tagihan' => 40999, 'sisa_tagihan' => 40999,
        ]);

        $gp = $this->groupProcessor();

        $gp->propagateCarryoverForNew([Invoice::find($idA1), Invoice::find($idB1)]);

        $invA2 = Invoice::find($idA2);
        $invB2 = Invoice::find($idB2);

        $this->assertEquals(50000.0, (float) $invA2->tagihan_periode_sebelumnya);
        $this->assertEquals(90000.0, (float) $invA2->total_tagihan);
        $this->assertEquals((float) $invA2->tagihan_periode_sebelumnya, (float) $invB2->tagihan_periode_sebelumnya, 'Klien A & B punya data identik — hasil preload batch harus sama untuk keduanya.');
        $this->assertEquals((float) $invA2->total_tagihan, (float) $invB2->total_tagihan);
        $this->assertEquals((float) $invA2->sisa_tagihan, (float) $invB2->sisa_tagihan);
    }

    public function test_propagate_carryover_for_new_kosong_tidak_error(): void
    {
        $this->seedSchema();

        $gp = $this->groupProcessor();
        $gp->propagateCarryoverForNew([]);

        $this->assertTrue(true, 'Tidak boleh throw saat daftar invoice baru kosong.');
    }

    // ──────────────────────────────────────────────────────────────
    //  Gap 2 — subtotal dihitung di memori saat insert
    // ──────────────────────────────────────────────────────────────

    public function test_insert_items_mengembalikan_subtotal_yang_sama_dengan_sum_dari_baris_yang_diinsert(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Klien Uji');
        $idInvoice = $this->insertInvoice(['tanggal_invoice' => '2026-07-05']);
        $invoice   = Invoice::find($idInvoice);

        $gp = $this->groupProcessor();

        // Kombinasi qty/harga "nakal" — pecahan qty x harga dengan sen, memaksa rounding per-item.
        $items = [
            ['nama_barang' => 'A', 'qty' => 1.333, 'harga_satuan' => 333.335],
            ['nama_barang' => 'B', 'qty' => 2.5, 'harga_satuan' => 1000.10],
            ['nama_barang' => 'C', 'qty' => 3, 'harga_satuan' => 4999.995],
        ];

        $expected = round(
            round(1.333 * 333.335, 2) + round(2.5 * 1000.10, 2) + round(3 * 4999.995, 2),
            2,
        );

        $subtotal = $this->invoke($gp, 'insertItems', $invoice, $items);

        $this->assertEquals($expected, $subtotal);

        // Payload InvoiceItem::insert() tetap RAW product (tidak berubah) — hanya cara
        // menghitung total in-memory yang berubah, bukan yang tersimpan per baris.
        $rawSum = (float) InvoiceItem::where('invoice_id', $idInvoice)->sum('subtotal');
        $this->assertEqualsWithDelta(1.333 * 333.335 + 2.5 * 1000.10 + 3 * 4999.995, $rawSum, 0.001);
    }

    public function test_insert_items_kosong_mengembalikan_nol_tanpa_insert(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Klien Uji');
        $idInvoice = $this->insertInvoice(['tanggal_invoice' => '2026-07-05']);

        $gp = $this->groupProcessor();
        $subtotal = $this->invoke($gp, 'insertItems', Invoice::find($idInvoice), []);

        $this->assertSame(0.0, $subtotal);
        $this->assertSame(0, InvoiceItem::where('invoice_id', $idInvoice)->count());
    }

    public function test_recompute_subtotal_pakai_parameter_bukan_query_sum(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Klien Uji');
        $idInvoice = $this->insertInvoice([
            'tanggal_invoice' => '2026-07-05', 'tagihan_periode_sebelumnya' => 25000,
        ]);
        $invoice = Invoice::find($idInvoice);

        $gp = $this->groupProcessor();
        // Sengaja TIDAK insert InvoiceItem apa pun — kalau recomputeSubtotal() masih diam-diam
        // query SUM(subtotal), hasilnya akan 0 (bukan 99999 dari parameter), test ini gagal.
        $this->invoke($gp, 'recomputeSubtotal', $invoice, 99999.0);

        $fresh = Invoice::find($idInvoice);
        $this->assertEquals(99999.0, (float) $fresh->subtotal);
        $this->assertEquals(124999.0, (float) $fresh->total_tagihan);
        $this->assertEquals(124999.0, (float) $fresh->sisa_tagihan);
    }

    // ──────────────────────────────────────────────────────────────
    //  Gap 3 — fresh()/refresh() dihapus dari createInvoice()/updateInvoice()
    // ──────────────────────────────────────────────────────────────

    public function test_create_invoice_tidak_memicu_query_fresh_setelah_insert(): void
    {
        $this->seedSchema();
        $this->insertKlien(1, 'Klien Uji');

        $klien = \App\Models\KlienAr::find(1);
        $gp    = $this->groupProcessor();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $result = $this->invoke(
            $gp,
            'createInvoice',
            'B2B',
            1,
            ['tanggal_invoice' => '2026-07-05'],
            [['nama_barang' => 'Barang A', 'qty' => 1, 'harga_satuan' => 10000]],
            [1 => $klien],
            null,
        );

        $selectByIdQueries = $this->countSelectByIdQueries(DB::getQueryLog(), $result->invoice->id);
        DB::disableQueryLog();

        $this->assertTrue($result->isInserted());
        $this->assertSame(0, $selectByIdQueries, 'createInvoice() tidak boleh lagi melakukan SELECT ulang (fresh()) setelah insert.');
    }

    /** @param  array<int, array{query: string}>  $log */
    private function countSelectByIdQueries(array $log, int $invoiceId): int
    {
        return count(array_filter($log, function ($entry) use ($invoiceId) {
            $sql = strtolower($entry['query']);

            return str_starts_with($sql, 'select') && str_contains($sql, 'tb_invoice') && str_contains($sql, 'id');
        }));
    }
}
