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
            $table->boolean('dibuat_dari_rekonsiliasi')->default(false)->after('sumber_pembayaran_ar_id');
        });
    }

    public function down(): void
    {
        Schema::table('tb_pembayaran_ar', function (Blueprint $table) {
            $table->dropColumn('dibuat_dari_rekonsiliasi');
        });
    }
};
