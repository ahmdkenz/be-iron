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
        Schema::table('tb_karyawan', function (Blueprint $table) {
            $table->dropColumn('fonnte_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tb_karyawan', function (Blueprint $table) {
            $table->string('fonnte_token')->nullable()->after('keterangan');
        });
    }
};
