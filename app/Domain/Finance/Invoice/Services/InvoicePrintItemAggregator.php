<?php

namespace App\Domain\Finance\Invoice\Services;

use Illuminate\Support\Collection;

/**
 * Meringkas item invoice PT/B2B untuk cetakan: baris dengan kode_barang +
 * nama_barang + satuan + harga_satuan yang sama digabung jadi satu baris
 * (qty & subtotal dijumlahkan), agar invoice dengan ratusan baris delivery
 * tidak mencetak ratusan halaman. Urutan hasil mengikuti kemunculan pertama
 * tiap grup pada koleksi asal.
 *
 * Tidak dipakai untuk invoice RESTO/B2C — detail per-baris (termasuk info
 * resto/delivery) harus tetap tampil apa adanya di cetakan.
 */
class InvoicePrintItemAggregator
{
    public static function aggregate(Collection $items): Collection
    {
        $groups = [];

        foreach ($items as $item) {
            $kodeBarang = $item->kode_barang ?? $item->barang?->kode_barang;

            $key = json_encode([
                $kodeBarang,
                $item->nama_barang,
                $item->satuan,
                (string) $item->harga_satuan,
            ]);

            if (!isset($groups[$key])) {
                $groups[$key] = (object) [
                    'kode_barang'  => $kodeBarang,
                    'nama_barang'  => $item->nama_barang,
                    'satuan'       => $item->satuan,
                    'harga_satuan' => $item->harga_satuan,
                    'keterangan'   => $item->keterangan,
                    'qty'          => 0,
                    'subtotal'     => 0,
                ];
            }

            $groups[$key]->qty += (float) $item->qty;
            $groups[$key]->subtotal += (float) $item->subtotal;
        }

        return collect(array_values($groups));
    }
}
