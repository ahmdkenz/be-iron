<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_investor', function (Blueprint $table) {
            $table->dropUnique('tb_investor_ktp_unique');
            $table->dropUnique('tb_investor_npwp_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tb_investor', function (Blueprint $table) {
            $table->unique('ktp');
            $table->unique('npwp');
        });
    }
};
