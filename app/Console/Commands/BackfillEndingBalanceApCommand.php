<?php

namespace App\Console\Commands;

use App\Domain\Finance\EndingBalanceAp\Services\EndingBalanceApService;
use Illuminate\Console\Command;

class BackfillEndingBalanceApCommand extends Command
{
    protected $signature   = 'ending-balance-ap:backfill {--user-id=1 : ID user yang tercatat sebagai created_by/updated_by}';
    protected $description = 'Buat/perbarui baris tb_ending_balance_ap untuk seluruh histori tagihan/pembayaran AP yang belum pernah tersinkron (mis. data yang sudah ada sebelum observer TagihanAp/PembayaranAp terpasang).';

    public function handle(EndingBalanceApService $service): int
    {
        $userId = (int) $this->option('user-id');

        $this->info('Memindai vendor+perusahaan yang punya aktivitas tagihan/pembayaran AP...');

        $result = $service->backfillAll($userId);

        $this->info("Selesai. Pasangan vendor+perusahaan diproses: {$result['pairs']}, periode disinkronkan: {$result['periods_synced']}.");

        return self::SUCCESS;
    }
}
