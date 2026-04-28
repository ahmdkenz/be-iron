<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_perusahaan', function (Blueprint $table) {
            $table->string('alamat', 255)->nullable()->after('nama_singkatan_perusahaan');
            $table->string('kota', 100)->nullable()->after('alamat');
            $table->string('kode_pos', 10)->nullable()->after('kota');
            $table->string('no_telp', 30)->nullable()->after('kode_pos');
            $table->string('email', 100)->nullable()->after('no_telp');
            $table->string('no_npwp', 30)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('tb_perusahaan', function (Blueprint $table) {
            $table->dropColumn(['alamat', 'kota', 'kode_pos', 'no_telp', 'email', 'no_npwp']);
        });
    }
};
