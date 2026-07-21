<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pengaman terakhir terhadap race condition pada generateNoDokumen() di
        // EndingBalanceApKoreksiService — kolom tetap nullable (KOREKSI_SALDO tidak
        // punya no_dokumen), MySQL mengizinkan banyak baris NULL pada unique index.
        Schema::table('tb_ending_balance_ap_koreksi', function (Blueprint $table) {
            $table->unique('no_dokumen', 'uniq_eb_ap_koreksi_no_dokumen');
        });
    }

    public function down(): void
    {
        Schema::table('tb_ending_balance_ap_koreksi', function (Blueprint $table) {
            $table->dropUnique('uniq_eb_ap_koreksi_no_dokumen');
        });
    }
};
