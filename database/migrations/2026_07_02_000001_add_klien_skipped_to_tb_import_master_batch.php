<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_import_master_batch', function (Blueprint $table) {
            $table->unsignedInteger('klien_skipped')->default(0)->after('klien_failed');
        });
    }

    public function down(): void
    {
        Schema::table('tb_import_master_batch', function (Blueprint $table) {
            $table->dropColumn('klien_skipped');
        });
    }
};
