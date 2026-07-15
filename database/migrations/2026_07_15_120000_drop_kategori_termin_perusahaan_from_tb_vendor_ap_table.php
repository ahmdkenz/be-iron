<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_vendor_ap', function (Blueprint $table) {
            $table->dropForeign(['perusahaan_id']);
            $table->dropColumn(['perusahaan_id', 'kategori', 'termin_hari']);
        });
    }

    public function down(): void
    {
        Schema::table('tb_vendor_ap', function (Blueprint $table) {
            $table->string('kategori')->nullable();
            $table->unsignedInteger('termin_hari')->nullable();
            $table->foreignId('perusahaan_id')->nullable()->constrained('tb_perusahaan')->restrictOnDelete();
        });
    }
};
