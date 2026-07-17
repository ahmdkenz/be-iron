<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tagihan AP kini dihapus permanen langsung (tanpa sampah), samakan
        // data lama: baris yang sudah soft-deleted dipurge dulu (anak-anaknya
        // ikut cascade otomatis) sebelum kolom deleted_at benar-benar hilang —
        // supaya baris itu tidak "hidup lagi" begitu global scope soft-delete dicabut.
        DB::table('tb_tagihan_ap')->whereNotNull('deleted_at')->delete();

        Schema::table('tb_tagihan_ap', function (Blueprint $table) {
            $table->dropColumn('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('tb_tagihan_ap', function (Blueprint $table) {
            $table->softDeletes();
        });
    }
};
