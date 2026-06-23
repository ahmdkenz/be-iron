<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_invoice', function (Blueprint $table) {
            $table->dropColumn(['periode_awal', 'periode_akhir', 'tanggal_kirim_barang']);
        });
    }

    public function down(): void
    {
        Schema::table('tb_invoice', function (Blueprint $table) {
            $table->date('periode_awal')->nullable()->after('tanggal_jatuh_tempo');
            $table->date('periode_akhir')->nullable()->after('periode_awal');
            $table->date('tanggal_kirim_barang')->nullable()->after('tanggal_invoice');
        });
    }
};
