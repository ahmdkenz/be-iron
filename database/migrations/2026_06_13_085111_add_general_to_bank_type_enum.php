<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tb_bank_statement MODIFY COLUMN bank_type ENUM('BCA','MANDIRI','BNI','BRI','CIMB','BSI','GENERAL') NOT NULL DEFAULT 'GENERAL'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tb_bank_statement MODIFY COLUMN bank_type ENUM('BCA','MANDIRI','BNI','BRI','CIMB','BSI') NOT NULL");
    }
};
