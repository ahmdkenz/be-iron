<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_ending_balance_ap_koreksi', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ending_balance_ap_id');
            $table->unsignedBigInteger('vendor_ap_id');

            // Tautan opsional ke tagihan tertentu. Jika terisi, koreksi ini adalah
            // penyesuaian per-tagihan yang mengubah sisa hutang tagihan tersebut.
            $table->unsignedBigInteger('tagihan_ap_id')->nullable();

            // Tipe koreksi: KOREKSI_SALDO, CREDIT_NOTE, DEBIT_NOTE
            $table->string('tipe', 30)->default('KOREKSI_SALDO');

            // Nomor dokumen resmi untuk CN dan DN (CN-AP-YYYYMM-XXXX / DN-AP-YYYYMM-XXXX)
            $table->string('no_dokumen', 30)->nullable();

            $table->decimal('nilai_koreksi', 15, 2);  // positif = tambah hutang, negatif = kurangi hutang
            $table->text('alasan_koreksi');
            $table->string('dokumen_url', 500)->nullable();

            // Approval satu tahap: PENDING -> APPROVED / REJECTED (Manager/Supervisor/Admin)
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');

            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('approved_note')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->unsignedBigInteger('submitted_by');
            $table->timestamp('submitted_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index('ending_balance_ap_id');
            $table->index('vendor_ap_id');
            $table->index('status');

            $table->foreign('ending_balance_ap_id')
                ->references('id')->on('tb_ending_balance_ap')
                ->cascadeOnDelete();

            $table->foreign('vendor_ap_id')
                ->references('id')->on('tb_vendor_ap')
                ->restrictOnDelete();

            $table->foreign('tagihan_ap_id')
                ->references('id')->on('tb_tagihan_ap')
                ->nullOnDelete();

            $table->foreign('approved_by')
                ->references('id')->on('tb_users')
                ->nullOnDelete();

            $table->foreign('submitted_by')
                ->references('id')->on('tb_users')
                ->restrictOnDelete();

            $table->foreign('updated_by')
                ->references('id')->on('tb_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_ending_balance_ap_koreksi');
    }
};
