<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_ending_balance_koreksi', function (Blueprint $table) {
            // Tipe koreksi: KOREKSI_SALDO (existing), CREDIT_NOTE, DEBIT_NOTE, KOREKSI_QTY_HARGA
            $table->string('tipe', 30)->default('KOREKSI_SALDO')->after('invoice_id');

            // Nomor dokumen resmi untuk CN dan DN (CN-YYYYMM-XXXX / DN-YYYYMM-XXXX)
            $table->string('no_dokumen', 30)->nullable()->after('tipe');
        });
    }

    public function down(): void
    {
        Schema::table('tb_ending_balance_koreksi', function (Blueprint $table) {
            $table->dropColumn(['tipe', 'no_dokumen']);
        });
    }
};
