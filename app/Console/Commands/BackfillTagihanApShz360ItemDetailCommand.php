<?php

namespace App\Console\Commands;

use App\Models\TagihanAp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillTagihanApShz360ItemDetailCommand extends Command
{
    protected $signature = 'ap:backfill-shz360-tagihan-item-detail';

    protected $description = 'Isi ulang qty_po/ppn/status_detail_terima_po/qty_tolak/keterangan_tolak pada item Tagihan AP hasil konversi SHZ360 yang dibuat sebelum field ini ada';

    public function handle(): int
    {
        $tagihans = TagihanAp::whereNotNull('ap_shz360_receipt_import_id')
            ->where('source_system', 'SHZ360')
            ->with(['items', 'apShz360ReceiptImport.items.poImportItem'])
            ->get();

        $backfilled = 0;
        $skipped = 0;

        foreach ($tagihans as $tagihan) {
            $tagihanItems = $tagihan->items()->orderBy('id')->get();
            $receiptItems = $tagihan->apShz360ReceiptImport?->items->sortBy('id')->values();

            if (! $receiptItems || $tagihanItems->count() !== $receiptItems->count()) {
                $this->warn("Dilewati {$tagihan->no_tagihan}: jumlah item tidak sama (tagihan: {$tagihanItems->count()}, receipt: " . ($receiptItems?->count() ?? 0) . ')');
                $skipped++;
                continue;
            }

            $mismatch = false;
            foreach ($tagihanItems as $index => $tagihanItem) {
                if ($tagihanItem->kode_barang !== $receiptItems[$index]->kode_barang) {
                    $mismatch = true;
                    break;
                }
            }

            if ($mismatch) {
                $this->warn("Dilewati {$tagihan->no_tagihan}: urutan/kode_barang tidak cocok (kemungkinan sudah diedit manual)");
                $skipped++;
                continue;
            }

            DB::transaction(function () use ($tagihanItems, $receiptItems) {
                foreach ($tagihanItems as $index => $tagihanItem) {
                    $receiptItem = $receiptItems[$index];

                    $tagihanItem->update([
                        'qty_po' => $receiptItem->poImportItem?->qty_po !== null ? (float) $receiptItem->poImportItem->qty_po : null,
                        'ppn' => $receiptItem->poImportItem?->ppn !== null ? (float) $receiptItem->poImportItem->ppn : null,
                        'status_detail_terima_po' => $receiptItem->status_detail_terima_po,
                        'qty_tolak' => (float) $receiptItem->qty_tolak,
                        'keterangan_tolak' => $receiptItem->keterangan_tolak,
                    ]);
                }
            });

            $backfilled++;
        }

        $this->info("Selesai. Diproses: {$tagihans->count()}, di-backfill: {$backfilled}, dilewati: {$skipped}.");

        return self::SUCCESS;
    }
}
