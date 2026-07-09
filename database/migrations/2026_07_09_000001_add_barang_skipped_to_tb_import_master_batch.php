<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_import_master_batch', function (Blueprint $table) {
            $table->unsignedInteger('barang_skipped')->default(0)->after('barang_updated');
        });
    }

    public function down(): void
    {
        Schema::table('tb_import_master_batch', function (Blueprint $table) {
            $table->dropColumn('barang_skipped');
        });
    }
};
