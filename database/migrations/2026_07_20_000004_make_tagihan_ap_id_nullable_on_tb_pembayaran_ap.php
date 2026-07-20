<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Beda dengan tb_pembayaran_ar.invoice_id (tidak punya FK aktif), kolom
    // tagihan_ap_id di sini PUNYA foreign key aktif (cascadeOnDelete), jadi
    // constraint-nya harus di-drop dulu sebelum kolom bisa dibuat nullable,
    // lalu di-re-add. Voucher AP multi-vendor tidak lagi mengisi kolom ini
    // (satu-satunya sumber kebenaran alokasi pindah ke tb_pembayaran_ap_items),
    // tapi kolom dipertahankan nullable untuk data lama.
    public function up(): void
    {
        Schema::table('tb_pembayaran_ap', function ($table) {
            $table->dropForeign('tb_pembayaran_ap_tagihan_ap_id_foreign');
        });

        DB::statement('ALTER TABLE tb_pembayaran_ap MODIFY tagihan_ap_id BIGINT UNSIGNED NULL');

        Schema::table('tb_pembayaran_ap', function ($table) {
            $table->foreign('tagihan_ap_id')
                  ->references('id')->on('tb_tagihan_ap')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_pembayaran_ap', function ($table) {
            $table->dropForeign('tb_pembayaran_ap_tagihan_ap_id_foreign');
        });

        DB::statement('ALTER TABLE tb_pembayaran_ap MODIFY tagihan_ap_id BIGINT UNSIGNED NOT NULL');

        Schema::table('tb_pembayaran_ap', function ($table) {
            $table->foreign('tagihan_ap_id')
                  ->references('id')->on('tb_tagihan_ap')
                  ->cascadeOnDelete();
        });
    }
};
