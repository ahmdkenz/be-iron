<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabel staging: menampung baris rekening koran hasil parsing sebelum
        // difinalisasi jadi tb_bank_statement_detail. Dihapus lagi setelah batch
        // completed/failed (lihat ImportBankStatementJob::cleanup()).
        Schema::create('tb_bank_statement_import_rows', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id');
            $table->unsignedInteger('row_number');
            $table->date('tanggal');
            $table->string('waktu_transaksi', 8)->nullable();
            $table->text('keterangan')->nullable();
            $table->string('no_referensi')->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('kredit', 15, 2)->default(0);
            $table->decimal('saldo', 15, 2)->default(0);

            $table->foreign('batch_id')
                ->references('id')->on('tb_bank_statement_import_batch')
                ->cascadeOnDelete();

            $table->index('batch_id');
            $table->index('tanggal');
            $table->index('no_referensi');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_bank_statement_import_rows');
    }
};
