<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * is_committed default true supaya seluruh tb_bank_statement lama (dibuat
     * sebelum kolom ini ada) otomatis tetap terlihat tanpa backfill terpisah.
     * Hanya statement baru yang sengaja dibuat is_committed=false oleh
     * BankStatementImportService::finalize() selama proses insert+auto_matching
     * berjalan, lalu di-flip true di akhir transaksi yang sama — supaya kalau
     * worker mati di tengah proses, transaksi rollback total dan tidak ada
     * statement "draft" yang bocor ke tabel manapun (rekonsiliasi bank, laporan
     * rekening koran, dst).
     */
    public function up(): void
    {
        Schema::table('tb_bank_statement', function (Blueprint $table) {
            $table->boolean('is_committed')->default(true)->after('uploaded_by');
            $table->uuid('import_batch_id')->nullable()->after('is_committed');

            $table->index('is_committed');

            $table->foreign('import_batch_id')
                ->references('id')->on('tb_bank_statement_import_batch')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_bank_statement', function (Blueprint $table) {
            $table->dropForeign(['import_batch_id']);
            $table->dropIndex(['is_committed']);
            $table->dropColumn(['is_committed', 'import_batch_id']);
        });
    }
};
