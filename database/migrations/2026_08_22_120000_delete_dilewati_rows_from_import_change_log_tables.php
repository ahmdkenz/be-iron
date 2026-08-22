<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Hapus permanen baris riwayat 'dilewati' — sekarang baris skip tidak lagi dicatat ke
    // changelog (lihat MasterImportService/OpeningBalanceImportService::recordChange), jadi
    // baris lama yang sudah kadung tersimpan dibersihkan juga. Irreversible by design — down()
    // tidak bisa mengembalikan data yang sudah dihapus.
    public function up(): void
    {
        DB::table('tb_import_master_change_log')->where('change_type', 'dilewati')->delete();
        DB::table('tb_opening_balance_import_change_log')->where('change_type', 'dilewati')->delete();
    }

    public function down(): void
    {
        // Data yang dihapus tidak bisa dikembalikan.
    }
};
