<?php

namespace App\Domain\Finance\VendorAp\Observers;

use App\Domain\Finance\ApShz360Sync\Services\ApShz360ImportService;
use App\Models\VendorAp;

class VendorApObserver
{
    public function __construct(private readonly ApShz360ImportService $importService) {}

    public function deleted(VendorAp $vendor): void
    {
        $this->importService->revertMappingAfterVendorDeleted($vendor->id);
    }
}
