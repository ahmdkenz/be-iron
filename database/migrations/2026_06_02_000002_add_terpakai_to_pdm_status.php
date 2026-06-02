<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tb_pendapatan_di_muka MODIFY COLUMN status ENUM('AKTIF','DIBATALKAN','TERPAKAI') DEFAULT 'AKTIF'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tb_pendapatan_di_muka MODIFY COLUMN status ENUM('AKTIF','DIBATALKAN') DEFAULT 'AKTIF'");
    }
};
