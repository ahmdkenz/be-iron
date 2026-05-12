<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_investor', function (Blueprint $table) {
            $table->renameColumn('alamat', 'kode_cabang');
            $table->renameColumn('keterangan', 'id_cabang');
        });
    }

    public function down(): void
    {
        Schema::table('tb_investor', function (Blueprint $table) {
            $table->renameColumn('kode_cabang', 'alamat');
            $table->renameColumn('id_cabang', 'keterangan');
        });
    }
};
