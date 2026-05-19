<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_bank_statement_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained('tb_bank_statement')->cascadeOnDelete();
            $table->date('tanggal');
            $table->text('keterangan');
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('kredit', 15, 2)->default(0);
            $table->decimal('saldo', 15, 2)->default(0);
            $table->enum('status_cocok', ['MATCHED', 'POSSIBLE', 'UNMATCHED', 'DIABAIKAN'])->default('UNMATCHED');
            $table->foreignId('pembayaran_ar_id')->nullable()->constrained('tb_pembayaran_ar')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_bank_statement_detail');
    }
};
