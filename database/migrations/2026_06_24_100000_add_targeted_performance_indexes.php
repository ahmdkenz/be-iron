<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // tb_invoice — composite indexes untuk pola query paling umum
        // (FK single-column sudah di-index otomatis oleh InnoDB via FK constraint)
        Schema::table('tb_invoice', function (Blueprint $table) {
            // "invoice klien X dengan status Y" — pola paling sering di AR
            $table->index(['klien_ar_id', 'status'], 'tb_invoice_klien_status_index');
            // "daftar invoice klien X urut tanggal" — laporan piutang
            $table->index(['klien_ar_id', 'tanggal_invoice'], 'tb_invoice_klien_tanggal_index');
            // "invoice perusahaan X dengan status Y" — dashboard multi-perusahaan
            $table->index(['perusahaan_id', 'status'], 'tb_invoice_perusahaan_status_index');
        });

        // tb_pembayaran_ar — filter tanggal untuk laporan pembayaran
        Schema::table('tb_pembayaran_ar', function (Blueprint $table) {
            $table->index('tanggal_pembayaran');
        });

        // tb_bank_statement_detail — tabel volume tinggi, sering difilter saat rekonsiliasi
        Schema::table('tb_bank_statement_detail', function (Blueprint $table) {
            $table->index('tanggal');
            $table->index('status_cocok');
            // "transaksi unmatched dalam statement X" — pattern utama halaman rekonsiliasi
            $table->index(['bank_statement_id', 'status_cocok'], 'tb_bank_detail_stmt_status_index');
        });

        // tb_klien_ar — filter enum & status pada listing klien
        Schema::table('tb_klien_ar', function (Blueprint $table) {
            $table->index('tipe_klien');
            $table->index('status');
        });

        // tb_resto — filter status aktif/non-aktif
        Schema::table('tb_resto', function (Blueprint $table) {
            $table->index('status');
        });

        // tb_ending_balance_koreksi — filter tipe koreksi (kolom baru)
        Schema::table('tb_ending_balance_koreksi', function (Blueprint $table) {
            $table->index('tipe');
        });

        // tb_pendapatan_di_muka — filter status & tanggal pencatatan
        Schema::table('tb_pendapatan_di_muka', function (Blueprint $table) {
            $table->index('status');
            $table->index('tanggal_pencatatan');
        });
    }

    public function down(): void
    {
        Schema::table('tb_invoice', function (Blueprint $table) {
            $table->dropIndex('tb_invoice_klien_status_index');
            $table->dropIndex('tb_invoice_klien_tanggal_index');
            $table->dropIndex('tb_invoice_perusahaan_status_index');
        });

        Schema::table('tb_pembayaran_ar', function (Blueprint $table) {
            $table->dropIndex(['tanggal_pembayaran']);
        });

        Schema::table('tb_bank_statement_detail', function (Blueprint $table) {
            $table->dropIndex(['tanggal']);
            $table->dropIndex(['status_cocok']);
            $table->dropIndex('tb_bank_detail_stmt_status_index');
        });

        Schema::table('tb_klien_ar', function (Blueprint $table) {
            $table->dropIndex(['tipe_klien']);
            $table->dropIndex(['status']);
        });

        Schema::table('tb_resto', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('tb_ending_balance_koreksi', function (Blueprint $table) {
            $table->dropIndex(['tipe']);
        });

        Schema::table('tb_pendapatan_di_muka', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['tanggal_pencatatan']);
        });
    }
};
