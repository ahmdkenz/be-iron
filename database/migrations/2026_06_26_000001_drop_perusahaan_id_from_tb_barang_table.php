<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_barang', function (Blueprint $table) {
            $table->dropForeign(['perusahaan_id']);
            $table->dropColumn('perusahaan_id');
        });
    }

    public function down(): void
    {
        Schema::table('tb_barang', function (Blueprint $table) {
            $table->unsignedBigInteger('perusahaan_id')->nullable()->after('spesifikasi');
            $table->foreign('perusahaan_id')->references('id')->on('tb_perusahaan')->onDelete('restrict');
        });
    }
};
