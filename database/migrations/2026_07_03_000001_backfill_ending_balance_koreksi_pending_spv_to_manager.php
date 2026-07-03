<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Approval Ending Balance kini satu tahap: koreksi langsung menunggu final approval
     * (PENDING_MANAGER) yang dapat diproses oleh Manager atau Supervisor.
     *
     * Backfill koreksi aktif lama yang masih PENDING_SPV agar langsung muncul di
     * tabel approval final dan tidak tertinggal di alur dua tahap yang sudah dihapus.
     */
    public function up(): void
    {
        DB::table('tb_ending_balance_koreksi')
            ->where('status', 'PENDING_SPV')
            ->update(['status' => 'PENDING_MANAGER']);
    }

    public function down(): void
    {
        // Tidak dapat dibalik dengan aman: data PENDING_SPV asli tidak dibedakan
        // dari koreksi yang memang PENDING_MANAGER. Sengaja dibiarkan no-op.
    }
};
