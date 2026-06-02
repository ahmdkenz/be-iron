<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_pendapatan_di_muka', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sumber_pembayaran_ar_id');
            $table->unsignedBigInteger('bank_statement_detail_id');
            $table->unsignedBigInteger('investor_id')->nullable();
            $table->unsignedBigInteger('klien_ar_id');
            $table->decimal('jumlah', 15, 2);
            $table->date('tanggal_pencatatan');
            $table->text('keterangan')->nullable();
            $table->enum('status', ['AKTIF', 'DIBATALKAN'])->default('AKTIF');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique('sumber_pembayaran_ar_id');

            $table->foreign('sumber_pembayaran_ar_id')
                ->references('id')->on('tb_pembayaran_ar')
                ->restrictOnDelete();

            $table->foreign('bank_statement_detail_id')
                ->references('id')->on('tb_bank_statement_detail')
                ->restrictOnDelete();

            $table->foreign('investor_id')
                ->references('id')->on('tb_investor')
                ->nullOnDelete();

            $table->foreign('klien_ar_id')
                ->references('id')->on('tb_klien_ar')
                ->restrictOnDelete();

            $table->foreign('created_by')
                ->references('id')->on('tb_users')
                ->nullOnDelete();

            $table->foreign('updated_by')
                ->references('id')->on('tb_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_pendapatan_di_muka');
    }
};
