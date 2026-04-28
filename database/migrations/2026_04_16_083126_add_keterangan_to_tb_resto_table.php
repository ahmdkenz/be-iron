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
        Schema::table('tb_resto', function (Blueprint $table) {
            $table->text('keterangan')->nullable()->after('tgl_aktif');
        });
    }

    public function down(): void
    {
        Schema::table('tb_resto', function (Blueprint $table) {
            $table->dropColumn('keterangan');
        });
    }
};
