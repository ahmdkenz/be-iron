<?php

namespace App\Console\Commands;

use App\Domain\Finance\ApShz360Sync\Services\ApShz360ImportService;
use App\Domain\Finance\ApShz360Sync\Support\VendorNameMatcher;
use App\Models\ApSourceVendorMap;
use Illuminate\Console\Command;

class BackfillApShz360VendorCascade extends Command
{
    protected $signature = 'ap:shz360-backfill-vendor-cascade';

    protected $description = 'Terapkan pemetaan vendor SHZ360 yang sudah ada ke source_supplier_id lain dengan nama supplier yang sama (one-off maintenance)';

    public function handle(ApShz360ImportService $service): int
    {
        $groups = ApSourceVendorMap::where('source_system', 'SHZ360')
            ->get()
            ->groupBy(fn (ApSourceVendorMap $map) => VendorNameMatcher::normalize($map->source_supplier_name));

        $resolvedGroups = 0;
        $totalPo = 0;
        $totalReceipts = 0;
        $conflicts = [];

        foreach ($groups as $normalizedName => $maps) {
            if (! $normalizedName) {
                continue;
            }

            $distinctVendorIds = $maps->pluck('vendor_ap_id')->filter()->unique();

            if ($distinctVendorIds->count() === 0) {
                continue;
            }

            if ($distinctVendorIds->count() > 1) {
                $conflicts[] = [
                    'name' => $normalizedName,
                    'vendor_ap_ids' => $distinctVendorIds->values()->all(),
                ];

                continue;
            }

            $result = $service->cascadeVendorByName($normalizedName, $distinctVendorIds->first());

            if ($result['maps'] > 0 || $result['po'] > 0) {
                $resolvedGroups++;
                $totalPo += $result['po'];
                $totalReceipts += $result['receipts'];
            }
        }

        $this->info("Selesai. Grup ter-cascade: {$resolvedGroups}, PO ter-update: {$totalPo}, Terima PO ter-update: {$totalReceipts}.");

        if (count($conflicts) > 0) {
            $this->warn(count($conflicts) . ' nama supplier punya lebih dari satu Vendor AP (duplikat) — butuh rekonsiliasi manual:');

            foreach ($conflicts as $conflict) {
                $this->line("  - {$conflict['name']}: vendor_ap_id " . implode(', ', $conflict['vendor_ap_ids']));
            }
        }

        return self::SUCCESS;
    }
}
