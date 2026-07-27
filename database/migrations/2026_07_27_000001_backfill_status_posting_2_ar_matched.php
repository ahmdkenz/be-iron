<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Status posting AR kini otomatis (POSTED) saat sebuah baris rekening koran
     * dicocokkan — lihat BankStatementService::manualMatch()/autoMatch(). Endpoint
     * posting manual sudah dihapus, jadi baris AR MATCHED lama yang masih PENDING
     * (belum pernah di-posting manual sebelumnya) perlu disamakan agar laporan
     * Rekening Koran tidak menampilkan "Belum Posting" untuk transaksi yang
     * sebenarnya sudah tercocok sejak lama.
     *
     * AP (debit) sengaja tidak dibackfill — scope auto-post hanya untuk AR.
     */
    public function up(): void
    {
        DB::table('tb_bank_statement_detail')
            ->where('status_cocok', 'MATCHED')
            ->whereNotNull('pembayaran_ar_id')
            ->where(function ($q) {
                $q->whereNull('status_posting_2')->orWhere('status_posting_2', 'PENDING');
            })
            ->update([
                'status_posting_2' => 'POSTED',
                'posted_by'        => DB::raw('matched_by'),
                'posted_at'        => DB::raw('COALESCE(updated_at, created_at)'),
            ]);
    }

    public function down(): void
    {
        // Tidak dapat dibalik dengan aman: baris yang di-backfill di sini tidak
        // dapat dibedakan dari baris yang memang sudah POSTED sebelum migration ini
        // (mis. lewat endpoint posting manual yang sudah dihapus). Sengaja no-op.
    }
};
