<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dijalankan setelah migration tb_tagihan_ap: kolom vendor_ap_id di
        // tb_tagihan_ap adalah FK RESTRICT, jadi tagihan yang sudah trashed
        // harus hilang lebih dulu sebelum vendor yang direferensikannya dipurge.
        DB::table('tb_vendor_ap')->whereNotNull('deleted_at')->delete();

        Schema::table('tb_vendor_ap', function (Blueprint $table) {
            $table->dropColumn('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('tb_vendor_ap', function (Blueprint $table) {
            $table->softDeletes();
        });
    }
};
