<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Backfill 1 baris tb_pembayaran_ap_items untuk setiap tb_pembayaran_ap
    // lama (yang masih mengisi tagihan_ap_id langsung), supaya tb_pembayaran_ap_items
    // jadi satu-satunya sumber kebenaran alokasi (dipakai TagihanApService::recalculate()
    // dkk) tanpa perlu dua jalur kode berbeda untuk data lama vs baru.
    //
    // sisa_sebelum/sisa_sesudah dihitung dengan replay berurutan (per tagihan_ap_id,
    // urut created_at lalu id) mulai dari total_tagihan milik tagihan tsb — ini murni
    // untuk kelengkapan tampilan/print riwayat lama, bukan dipakai ulang untuk logika
    // bisnis (recalculate tetap re-sum langsung dari tabel items).
    public function up(): void
    {
        $payments = DB::table('tb_pembayaran_ap as p')
            ->join('tb_tagihan_ap as t', 't.id', '=', 'p.tagihan_ap_id')
            ->whereNotNull('p.tagihan_ap_id')
            ->orderBy('p.tagihan_ap_id')
            ->orderBy('p.created_at')
            ->orderBy('p.id')
            ->get([
                'p.id as pembayaran_ap_id',
                'p.tagihan_ap_id',
                'p.jumlah_pembayaran',
                't.vendor_ap_id',
                't.total_tagihan',
            ]);

        $runningSisa = [];
        $now         = now();
        $rows        = [];

        foreach ($payments as $p) {
            $tagihanId = $p->tagihan_ap_id;

            if (!array_key_exists($tagihanId, $runningSisa)) {
                $runningSisa[$tagihanId] = (float) $p->total_tagihan;
            }

            $sisaSebelum = $runningSisa[$tagihanId];
            $jumlah      = (float) $p->jumlah_pembayaran;
            $sisaSesudah = max(0.0, $sisaSebelum - $jumlah);

            $runningSisa[$tagihanId] = $sisaSesudah;

            $rows[] = [
                'pembayaran_ap_id'    => $p->pembayaran_ap_id,
                'tagihan_ap_id'       => $tagihanId,
                'vendor_ap_id'        => $p->vendor_ap_id,
                'jumlah_dialokasikan' => $jumlah,
                'sisa_sebelum'        => $sisaSebelum,
                'sisa_sesudah'        => $sisaSesudah,
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('tb_pembayaran_ap_items')->insert($chunk);
        }
    }

    public function down(): void
    {
        // Backfill murni derivatif dari tb_pembayaran_ap — aman dikosongkan total.
        DB::table('tb_pembayaran_ap_items')->truncate();
    }
};
