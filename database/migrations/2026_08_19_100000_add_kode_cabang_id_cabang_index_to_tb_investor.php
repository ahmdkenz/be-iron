<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lookup dedup Investor sekarang memprioritaskan kode_cabang/id_cabang (bukan nama —
        // lihat MasterImportService::investorDedupKey()), tapi tb_investor_dedup_index yang ada
        // leading dengan nama_investor sehingga tidak dipakai MySQL untuk query berbasis kode saja.
        // kode_cabang/id_cabang bertipe TEXT (lihat migration 2026_05_12_000001) — index butuh
        // key length eksplisit, prefix 100 karakter aman karena input divalidasi max:50.
        DB::statement('ALTER TABLE tb_investor ADD INDEX tb_investor_kode_cabang_id_cabang_index (kode_cabang(100), id_cabang(100))');
    }

    public function down(): void
    {
        Schema::table('tb_investor', function ($table) {
            $table->dropIndex('tb_investor_kode_cabang_id_cabang_index');
        });
    }
};
