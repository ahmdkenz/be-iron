<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Backfill sekali dari Client AR aktif: tipe_klien PT => B2B, RESTO => B2C.
    // Ini murni mengisi metadata segmen di Data Entitas; keputusan invoice
    // tetap dari KlienAr::tipe_klien, bukan kolom ini.
    public function up(): void
    {
        $tipeByPerusahaan = DB::table('tb_klien_ar')
            ->select('perusahaan_id', 'tipe_klien')
            ->where('status', true)
            ->whereNotNull('perusahaan_id')
            ->distinct()
            ->get()
            ->groupBy('perusahaan_id');

        $map = ['PT' => 'B2B', 'RESTO' => 'B2C'];
        $now = now();

        foreach ($tipeByPerusahaan as $perusahaanId => $rows) {
            $segmen = $rows->pluck('tipe_klien')
                ->map(fn($tipe) => $map[$tipe] ?? null)
                ->filter()
                ->unique()
                ->sort()
                ->values();

            if ($segmen->isEmpty()) {
                continue;
            }

            DB::table('tb_perusahaan')
                ->where('id', $perusahaanId)
                ->update([
                    'segmen'     => $segmen->toJson(),
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        DB::table('tb_perusahaan')->update(['segmen' => null]);
    }
};
