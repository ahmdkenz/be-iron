<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hapus duplikat sebelum menambah constraint — simpan yang terlama (id terkecil)
        DB::statement("
            DELETE p1 FROM tb_pembayaran_ar p1
            INNER JOIN tb_pembayaran_ar p2
            ON p1.no_referensi = p2.no_referensi
               AND p1.id > p2.id
            WHERE p1.no_referensi IS NOT NULL
        ");

        Schema::table('tb_pembayaran_ar', function (Blueprint $table) {
            $table->unique('no_referensi', 'uniq_pembayaran_no_referensi');
        });
    }

    public function down(): void
    {
        Schema::table('tb_pembayaran_ar', function (Blueprint $table) {
            $table->dropUnique('uniq_pembayaran_no_referensi');
        });
    }
};
