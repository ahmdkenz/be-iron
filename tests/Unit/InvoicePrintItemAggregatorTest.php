<?php

namespace Tests\Unit;

use App\Domain\Finance\Invoice\Services\InvoicePrintItemAggregator;
use App\Models\InvoiceItem;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Test murni tanpa DB — InvoiceItem dibuat via mass-assignment (bukan disimpan),
 * dan setiap item selalu diberi kode_barang eksplisit agar relasi `barang()`
 * tidak pernah diakses (lihat project_test_db_migrate_fresh_broken di memory).
 */
class InvoicePrintItemAggregatorTest extends TestCase
{
    private function makeItem(array $attributes): InvoiceItem
    {
        return new InvoiceItem(array_merge([
            'kode_barang'  => 'BRG-001',
            'nama_barang'  => 'Barang A',
            'satuan'       => 'PCS',
            'harga_satuan' => 1000,
            'qty'          => 1,
            'subtotal'     => 1000,
            'keterangan'   => null,
        ], $attributes));
    }

    public function test_duplikat_dengan_key_sama_digabung(): void
    {
        $items = new Collection([
            $this->makeItem(['qty' => 2, 'subtotal' => 2000]),
            $this->makeItem(['qty' => 3, 'subtotal' => 3000]),
        ]);

        $result = InvoicePrintItemAggregator::aggregate($items);

        $this->assertCount(1, $result);
        $this->assertSame(5.0, $result->first()->qty);
        $this->assertSame(5000.0, $result->first()->subtotal);
    }

    public function test_nama_sama_tapi_kode_berbeda_tetap_terpisah(): void
    {
        $items = new Collection([
            $this->makeItem(['kode_barang' => 'BRG-001', 'nama_barang' => 'Barang A']),
            $this->makeItem(['kode_barang' => 'BRG-002', 'nama_barang' => 'Barang A']),
        ]);

        $result = InvoicePrintItemAggregator::aggregate($items)->values();

        $this->assertCount(2, $result);
        $this->assertSame('BRG-001', $result[0]->kode_barang);
        $this->assertSame('BRG-002', $result[1]->kode_barang);
    }

    public function test_nama_sama_tapi_satuan_berbeda_tetap_terpisah(): void
    {
        $items = new Collection([
            $this->makeItem(['satuan' => 'PCS']),
            $this->makeItem(['satuan' => 'BOX']),
        ]);

        $result = InvoicePrintItemAggregator::aggregate($items);

        $this->assertCount(2, $result);
    }

    public function test_nama_sama_tapi_harga_berbeda_tetap_terpisah(): void
    {
        $items = new Collection([
            $this->makeItem(['harga_satuan' => 1000]),
            $this->makeItem(['harga_satuan' => 1500]),
        ]);

        $result = InvoicePrintItemAggregator::aggregate($items);

        $this->assertCount(2, $result);
    }

    public function test_qty_dan_subtotal_hasil_gabungan_benar(): void
    {
        $items = new Collection([
            $this->makeItem(['qty' => 1.5, 'subtotal' => 15000]),
            $this->makeItem(['qty' => 2.25, 'subtotal' => 22500]),
            $this->makeItem(['qty' => 0.25, 'subtotal' => 2500]),
        ]);

        $result = InvoicePrintItemAggregator::aggregate($items);

        $this->assertCount(1, $result);
        $this->assertEqualsWithDelta(4.0, $result->first()->qty, 0.0001);
        $this->assertEqualsWithDelta(40000.0, $result->first()->subtotal, 0.0001);
    }

    public function test_urutan_grup_mengikuti_kemunculan_pertama(): void
    {
        $items = new Collection([
            $this->makeItem(['kode_barang' => 'BRG-C', 'nama_barang' => 'Barang C']),
            $this->makeItem(['kode_barang' => 'BRG-A', 'nama_barang' => 'Barang A']),
            $this->makeItem(['kode_barang' => 'BRG-C', 'nama_barang' => 'Barang C']),
            $this->makeItem(['kode_barang' => 'BRG-B', 'nama_barang' => 'Barang B']),
        ]);

        $result = InvoicePrintItemAggregator::aggregate($items)->values();

        $this->assertSame(['BRG-C', 'BRG-A', 'BRG-B'], $result->pluck('kode_barang')->all());
    }

    public function test_koleksi_kosong_menghasilkan_koleksi_kosong(): void
    {
        $result = InvoicePrintItemAggregator::aggregate(new Collection());

        $this->assertCount(0, $result);
    }
}
