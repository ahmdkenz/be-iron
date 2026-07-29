<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Menopang query failStale() (WHERE status='processing' AND updated_at < ...)
        // agar tidak scan/lock seluruh baris berstatus 'processing' lewat index
        // status-only saja — termasuk baris yang sedang aktif ditulis worker.
        Schema::table('tb_bank_statement_import_batch', function (Blueprint $table) {
            $table->index(['status', 'updated_at'], 'tb_bank_stmt_batch_status_updated_index');
        });
    }

    public function down(): void
    {
        Schema::table('tb_bank_statement_import_batch', function (Blueprint $table) {
            $table->dropIndex('tb_bank_stmt_batch_status_updated_index');
        });
    }
};
