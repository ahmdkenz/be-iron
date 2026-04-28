<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_klien_ar', function (Blueprint $table) {
            $table->enum('kategori_ar', ['INTERNAL', 'EKSTERNAL'])->default('EKSTERNAL')->after('tipe_klien');
        });

        DB::statement("ALTER TABLE tb_klien_ar MODIFY COLUMN tipe_klien ENUM('PT','RESTO','STOKIS','MITRA') NOT NULL DEFAULT 'RESTO'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tb_klien_ar MODIFY COLUMN tipe_klien ENUM('PT','RESTO','STOKIS') NOT NULL DEFAULT 'RESTO'");

        Schema::table('tb_klien_ar', function (Blueprint $table) {
            $table->dropColumn('kategori_ar');
        });
    }
};
