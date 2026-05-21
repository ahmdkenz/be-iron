<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tb_pembayaran_ar', function (Blueprint $table) {
            $table->unsignedBigInteger('sumber_pembayaran_ar_id')->nullable()->after('keterangan');
            $table->foreign('sumber_pembayaran_ar_id')
                  ->references('id')->on('tb_pembayaran_ar')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_pembayaran_ar', function (Blueprint $table) {
            $table->dropForeign(['sumber_pembayaran_ar_id']);
            $table->dropColumn('sumber_pembayaran_ar_id');
        });
    }
};
