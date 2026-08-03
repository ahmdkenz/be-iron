<?php

namespace App\Console\Commands;

use App\Models\BulkPrintToken;
use Illuminate\Console\Command;

class CleanupBulkPrintTokensCommand extends Command
{
    protected $signature = 'bulk-print-token:cleanup';

    protected $description = 'Hapus token bulk-print investor yang sudah lewat masa berlaku signed URL-nya (30 hari).';

    public function handle(): int
    {
        $deleted = BulkPrintToken::where('created_at', '<', now()->subDays(30))->delete();

        $this->info("Selesai: {$deleted} token bulk-print dihapus (kadaluarsa).");

        return self::SUCCESS;
    }
}
