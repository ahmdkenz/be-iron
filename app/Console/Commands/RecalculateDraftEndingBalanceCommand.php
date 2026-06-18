<?php

namespace App\Console\Commands;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceService;
use App\Models\EndingBalance;
use Illuminate\Console\Command;

class RecalculateDraftEndingBalanceCommand extends Command
{
    protected $signature   = 'ending-balance:recalculate-draft';
    protected $description = 'Hitung ulang seluruh ending balance berstatus DRAFT (memperbaiki nilai invoice_masuk/saldo_akhir/outstanding yang tersimpan dengan rumus lama). Baris LOCKED tidak disentuh.';

    public function handle(EndingBalanceService $service): int
    {
        $drafts = EndingBalance::where('status', 'DRAFT')->get();

        if ($drafts->isEmpty()) {
            $this->info('Tidak ada ending balance DRAFT untuk dihitung ulang.');
            return self::SUCCESS;
        }

        $this->info("Menghitung ulang {$drafts->count()} baris ending balance DRAFT...");
        $bar = $this->output->createProgressBar($drafts->count());
        $bar->start();

        $ok     = 0;
        $failed = 0;

        foreach ($drafts as $eb) {
            try {
                // updated_by: pakai aktor terakhir yang ada agar tidak menimpa dengan null.
                $userId = (int) ($eb->updated_by ?? $eb->created_by ?? 0);
                $service->recalculate($eb, $userId);
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->error("Gagal EB #{$eb->id}: {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Selesai. Berhasil: {$ok}, Gagal: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
