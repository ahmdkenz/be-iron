<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_bank_statement_detail', function (Blueprint $table) {
            $table->string('no_referensi', 100)->nullable()->after('keterangan');
        });
    }

    public function down(): void
    {
        Schema::table('tb_bank_statement_detail', function (Blueprint $table) {
            $table->dropColumn('no_referensi');
        });
    }
};
