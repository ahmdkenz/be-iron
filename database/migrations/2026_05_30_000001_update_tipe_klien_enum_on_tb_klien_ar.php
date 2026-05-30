<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Migrate legacy MITRA → RESTO and STOKIS → PT before tightening the enum
        DB::table('tb_klien_ar')->where('tipe_klien', 'MITRA')->update(['tipe_klien' => 'RESTO']);
        DB::table('tb_klien_ar')->where('tipe_klien', 'STOKIS')->update(['tipe_klien' => 'PT']);

        DB::statement("ALTER TABLE tb_klien_ar MODIFY COLUMN tipe_klien ENUM('PT','RESTO') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tb_klien_ar MODIFY COLUMN tipe_klien ENUM('PT','RESTO','STOKIS','MITRA') NOT NULL");
    }
};
