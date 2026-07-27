<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_perusahaan', function (Blueprint $table) {
            $table->json('segmen')->nullable()->after('nama_direktur');
        });
    }

    public function down(): void
    {
        Schema::table('tb_perusahaan', function (Blueprint $table) {
            $table->dropColumn('segmen');
        });
    }
};
