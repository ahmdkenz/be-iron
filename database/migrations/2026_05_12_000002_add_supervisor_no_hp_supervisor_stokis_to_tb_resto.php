<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_resto', function (Blueprint $table) {
            $table->string('supervisor')->nullable()->after('karyawan_id');
            $table->string('no_hp_supervisor', 20)->nullable()->after('supervisor');
            $table->string('stokis')->nullable()->after('no_hp_supervisor');
        });
    }

    public function down(): void
    {
        Schema::table('tb_resto', function (Blueprint $table) {
            $table->dropColumn(['supervisor', 'no_hp_supervisor', 'stokis']);
        });
    }
};
