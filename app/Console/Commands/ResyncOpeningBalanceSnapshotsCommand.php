<?php

namespace App\Console\Commands;

use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Models\Invoice;
use App\Models\OpeningBalanceDetail;
use Illuminate\Console\Command;

class ResyncOpeningBalanceSnapshotsCommand extends Command
{
    protected $signature   = 'ob:resync-snapshots';
    protected $description = 'Sinkronkan ulang snapshot sisa_tagihan_asal pada seluruh baris Opening Balance dari invoice reguler yang direferensikan (no_invoice_asal), lalu hitung ulang nominal OB. Memperbaiki data lama yang tidak ikut ter-recalculate saat invoice reguler dibayar.';

    public function handle(InvoiceService $service): int
    {
        // Ambil semua no_invoice_asal unik yang dirujuk baris OB, lalu cocokkan ke invoice reguler.
        $noInvoices = OpeningBalanceDetail::query()
            ->distinct()
            ->pluck('no_invoice_asal')
            ->filter()
            ->values();

        if ($noInvoices->isEmpty()) {
            $this->info('Tidak ada baris Opening Balance untuk disinkronkan.');
            return self::SUCCESS;
        }

        $invoices = Invoice::whereIn('no_invoice', $noInvoices)
            ->where('is_opening_balance', false)
            ->get();

        if ($invoices->isEmpty()) {
            $this->warn('Tidak ada invoice reguler yang cocok dengan referensi baris OB (mungkin data import manual).');
            return self::SUCCESS;
        }

        $this->info("Menyinkronkan snapshot OB dari {$invoices->count()} invoice reguler...");
        $bar = $this->output->createProgressBar($invoices->count());
        $bar->start();

        $ok     = 0;
        $failed = 0;

        foreach ($invoices as $invoice) {
            try {
                $service->resyncOpeningBalanceSnapshots($invoice);
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->error("Gagal invoice {$invoice->no_invoice}: {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Selesai. Berhasil: {$ok}, Gagal: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
