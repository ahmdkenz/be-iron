<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_barang', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
            $table->dropColumn('brand_id');
        });
    }

    public function down(): void
    {
        Schema::table('tb_barang', function (Blueprint $table) {
            $table->unsignedBigInteger('brand_id')->nullable()->after('spesifikasi');
            $table->foreign('brand_id')->references('id')->on('tb_brand')->onDelete('restrict');
        });
    }
};
