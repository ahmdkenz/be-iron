<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_klien_ar', function (Blueprint $table) {
            $table->string('no_wa', 20)->nullable()->after('no_npwp');
        });
    }

    public function down(): void
    {
        Schema::table('tb_klien_ar', function (Blueprint $table) {
            $table->dropColumn('no_wa');
        });
    }
};
