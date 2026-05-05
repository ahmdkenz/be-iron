<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_resto', function (Blueprint $table) {
            $table->dropForeign(['perusahaan_id']);
            $table->dropForeign(['brand_id']);

            $table->unsignedBigInteger('perusahaan_id')->nullable()->change();
            $table->unsignedBigInteger('brand_id')->nullable()->change();

            $table->foreign('perusahaan_id')->references('id')->on('tb_perusahaan')->nullOnDelete();
            $table->foreign('brand_id')->references('id')->on('tb_brand')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_resto', function (Blueprint $table) {
            $table->dropForeign(['perusahaan_id']);
            $table->dropForeign(['brand_id']);

            $table->unsignedBigInteger('perusahaan_id')->nullable(false)->change();
            $table->unsignedBigInteger('brand_id')->nullable(false)->change();

            $table->foreign('perusahaan_id')->references('id')->on('tb_perusahaan')->restrictOnDelete();
            $table->foreign('brand_id')->references('id')->on('tb_brand')->restrictOnDelete();
        });
    }
};
