<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_investor', function (Blueprint $table) {
            $table->string('npwp', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tb_investor', function (Blueprint $table) {
            $table->string('npwp', 20)->nullable()->change();
        });
    }
};
