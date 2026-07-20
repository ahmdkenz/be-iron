<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Kategori manual (BB = Bahan Baku, NBB = Non Bahan Baku), diisi user saat
    // membuat voucher baru. Data lama dibiarkan NULL dan tampil sebagai
    // "Belum Dikategorikan" di FE — sengaja tidak ada backfill.
    public function up(): void
    {
        Schema::table('tb_pembayaran_ap', function ($table) {
            $table->string('kategori_voucher')->nullable()->after('metode_pembayaran');
        });
    }

    public function down(): void
    {
        Schema::table('tb_pembayaran_ap', function ($table) {
            $table->dropColumn('kategori_voucher');
        });
    }
};
