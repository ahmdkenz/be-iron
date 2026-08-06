<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_opening_balance_detail', function (Blueprint $table) {
            $table->string('kode_resto')->nullable()->after('keterangan');
            $table->string('nama_resto')->nullable()->after('kode_resto');
        });
    }

    public function down(): void
    {
        Schema::table('tb_opening_balance_detail', function (Blueprint $table) {
            $table->dropColumn(['kode_resto', 'nama_resto']);
        });
    }
};
