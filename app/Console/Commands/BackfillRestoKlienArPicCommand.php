<?php

namespace App\Console\Commands;

use App\Models\KlienAr;
use Illuminate\Console\Command;

class BackfillRestoKlienArPicCommand extends Command
{
    protected $signature = 'resto:backfill-klien-ar-pic';

    protected $description = 'Sinkronkan karyawan_ar_id Client AR tipe RESTO agar sama dengan PIC (karyawan_id) Resto terkait (one-off maintenance)';

    public function handle(): int
    {
        $mismatches = KlienAr::query()
            ->where('tipe_klien', 'RESTO')
            ->whereNotNull('resto_id')
            ->join('tb_resto', 'tb_resto.id', '=', 'tb_klien_ar.resto_id')
            ->whereNotNull('tb_resto.karyawan_id')
            ->whereColumn('tb_klien_ar.karyawan_ar_id', '!=', 'tb_resto.karyawan_id')
            ->select('tb_klien_ar.id as klien_ar_id', 'tb_klien_ar.karyawan_ar_id as old_karyawan_ar_id', 'tb_resto.kode_resto', 'tb_resto.nama_resto', 'tb_resto.karyawan_id as resto_karyawan_id')
            ->get();

        if ($mismatches->isEmpty()) {
            $this->info('Tidak ada gap PIC AR yang ditemukan.');

            return self::SUCCESS;
        }

        foreach ($mismatches as $row) {
            KlienAr::whereKey($row->klien_ar_id)->update(['karyawan_ar_id' => $row->resto_karyawan_id]);

            $this->line("  - {$row->kode_resto} ({$row->nama_resto}): karyawan_ar_id {$row->old_karyawan_ar_id} -> {$row->resto_karyawan_id}");
        }

        $this->info("Selesai. {$mismatches->count()} Client AR disinkronkan mengikuti PIC Resto.");

        return self::SUCCESS;
    }
}
