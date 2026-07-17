<?php

namespace App\Domain\Finance\TagihanAp\Observers;

use App\Domain\Finance\ApShz360Sync\Services\ApShz360ImportService;
use App\Models\TagihanAp;

class TagihanApObserver
{
    public function __construct(private readonly ApShz360ImportService $importService) {}

    public function deleted(TagihanAp $tagihan): void
    {
        if (! $tagihan->ap_shz360_receipt_import_id) {
            return;
        }

        $this->importService->revertReceiptAfterTagihanDeleted($tagihan->ap_shz360_receipt_import_id);
    }
}
