<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_klien_ar', function (Blueprint $table) {
            $table->dropColumn('kategori_ar');
        });
    }

    public function down(): void
    {
        Schema::table('tb_klien_ar', function (Blueprint $table) {
            $table->enum('kategori_ar', ['INTERNAL', 'EKSTERNAL'])->default('EKSTERNAL')->after('tipe_klien');
        });
    }
};
