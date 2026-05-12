<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_klien_ar', function (Blueprint $table) {
            $table->dropColumn(['tipe_outlet', 'stokis_area']);
        });
    }

    public function down(): void
    {
        Schema::table('tb_klien_ar', function (Blueprint $table) {
            $table->string('tipe_outlet')->nullable()->after('tipe_klien');
            $table->string('stokis_area', 100)->nullable()->after('tipe_outlet');
        });
    }
};
