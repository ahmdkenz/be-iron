<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_users', function (Blueprint $table) {
            $table->foreign('karyawan_id')
                ->references('id')
                ->on('tb_karyawan')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_users', function (Blueprint $table) {
            $table->dropForeign(['karyawan_id']);
        });
    }
};
