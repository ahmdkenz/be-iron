<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Field kode_cabang/id_cabang pada Investor dihapus total — termasuk 2 index yang
     * merujuknya: tb_investor_dedup_index (2026_08_07_100000, komposit nama_investor +
     * kode_cabang + id_cabang) dan tb_investor_kode_cabang_id_cabang_index (2026_08_19_100000).
     * Dedup Investor di MasterImportService sekarang murni berbasis nama_investor (lihat
     * investorDedupKey()) — index komposit lama diganti index single-column nama_investor
     * supaya lookup bulk-import tetap tidak full-scan.
     *
     * CATATAN: down() mengembalikan SKEMA (kolom+index), BUKAN data — nilai kode_cabang/
     * id_cabang yang sudah ada akan hilang permanen saat up() dijalankan.
     */
    public function up(): void
    {
        Schema::table('tb_investor', function (Blueprint $table) {
            $table->dropIndex('tb_investor_kode_cabang_id_cabang_index');
            $table->dropIndex('tb_investor_dedup_index');
        });

        Schema::table('tb_investor', function (Blueprint $table) {
            $table->dropColumn(['kode_cabang', 'id_cabang']);
        });

        Schema::table('tb_investor', function (Blueprint $table) {
            $table->index('nama_investor', 'tb_investor_nama_investor_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tb_investor', function (Blueprint $table) {
            $table->dropIndex('tb_investor_nama_investor_index');
        });

        Schema::table('tb_investor', function (Blueprint $table) {
            // Posisi & tipe (TEXT) mengikuti migration asal 2026_05_12_000001 (rename dari
            // alamat/keterangan): kode_cabang setelah email, id_cabang setelah status.
            $table->text('kode_cabang')->nullable()->after('email');
            $table->text('id_cabang')->nullable()->after('status');
        });

        DB::statement('ALTER TABLE tb_investor ADD INDEX tb_investor_dedup_index (nama_investor, kode_cabang(100), id_cabang(100))');
        DB::statement('ALTER TABLE tb_investor ADD INDEX tb_investor_kode_cabang_id_cabang_index (kode_cabang(100), id_cabang(100))');
    }
};
