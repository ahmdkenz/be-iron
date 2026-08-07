<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kolom yang dipakai lookup dedup Import Master Data (Investor/Resto/Barang) —
        // sebelumnya tidak ter-index sama sekali sehingga tiap lookup adalah full table scan.

        // kode_cabang/id_cabang bertipe TEXT (bekas rename dari alamat/keterangan, lihat
        // migration 2026_05_12_000001) — MySQL mewajibkan key length untuk index kolom
        // TEXT/BLOB. Prefix 100 karakter aman karena input divalidasi max:50 di MasterImportService.
        DB::statement('ALTER TABLE tb_investor ADD INDEX tb_investor_dedup_index (nama_investor, kode_cabang(100), id_cabang(100))');

        Schema::table('tb_resto', function (Blueprint $table) {
            $table->index('kode_resto', 'tb_resto_kode_resto_index');
            $table->index('nama_resto', 'tb_resto_nama_resto_index');
        });

        Schema::table('tb_klien_ar', function (Blueprint $table) {
            // fallback lookup path (nama_klien+tipe_klien) saat perusahaan_id/resto_id kosong
            $table->index('nama_klien', 'tb_klien_ar_nama_klien_index');
        });

        // Non-unique (bukan unique) — tb_barang saat ini tidak punya constraint uniqueness
        // di kode_barang; menambah unique adalah keputusan data-integrity terpisah yang butuh
        // audit duplikat data lama dulu, di luar scope perubahan ini.
        Schema::table('tb_barang', function (Blueprint $table) {
            $table->index('kode_barang', 'tb_barang_kode_barang_index');
        });
    }

    public function down(): void
    {
        Schema::table('tb_investor', function (Blueprint $table) {
            $table->dropIndex('tb_investor_dedup_index');
        });

        Schema::table('tb_resto', function (Blueprint $table) {
            $table->dropIndex('tb_resto_kode_resto_index');
            $table->dropIndex('tb_resto_nama_resto_index');
        });

        Schema::table('tb_klien_ar', function (Blueprint $table) {
            $table->dropIndex('tb_klien_ar_nama_klien_index');
        });

        Schema::table('tb_barang', function (Blueprint $table) {
            $table->dropIndex('tb_barang_kode_barang_index');
        });
    }
};
