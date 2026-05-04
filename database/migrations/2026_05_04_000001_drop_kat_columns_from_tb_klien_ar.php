<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_klien_ar', function (Blueprint $table) {
            $table->dropColumn(['kat_1', 'kat_2']);
        });
    }

    public function down(): void
    {
        Schema::table('tb_klien_ar', function (Blueprint $table) {
            $table->string('kat_1', 100)->nullable()->after('no_npwp');
            $table->string('kat_2', 100)->nullable()->after('kat_1');
        });
    }
};
