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
        Schema::table('tb_brand', function (Blueprint $table) {
            $table->dropForeign(['perusahaan_id']);
            $table->dropColumn('perusahaan_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tb_brand', function (Blueprint $table) {
            $table->foreignId('perusahaan_id')->after('nama_brand')->constrained('tb_perusahaan')->restrictOnDelete();
        });
    }
};
