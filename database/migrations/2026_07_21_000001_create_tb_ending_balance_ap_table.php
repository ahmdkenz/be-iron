<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_ending_balance_ap', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_ap_id');
            $table->unsignedBigInteger('perusahaan_id');
            $table->date('periode_awal');
            $table->date('periode_akhir');

            // Komponen kalkulasi — immutable setelah status LOCKED
            $table->decimal('saldo_awal', 15, 2)->default(0);
            $table->decimal('tagihan_masuk', 15, 2)->default(0);
            $table->decimal('pembayaran', 15, 2)->default(0);
            $table->decimal('saldo_akhir_sistem', 15, 2)->default(0);

            // Nilai final = saldo_akhir_sistem + SUM(koreksi APPROVED)
            $table->decimal('saldo_akhir_final', 15, 2)->default(0);

            $table->enum('status', ['DRAFT', 'LOCKED'])->default('DRAFT');
            $table->unsignedBigInteger('locked_by')->nullable();
            $table->timestamp('locked_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['vendor_ap_id', 'perusahaan_id', 'periode_awal', 'periode_akhir'], 'uq_ending_balance_ap');

            $table->index('vendor_ap_id');
            $table->index(['periode_awal', 'periode_akhir']);
            $table->index('status');

            $table->foreign('vendor_ap_id')
                ->references('id')->on('tb_vendor_ap')
                ->restrictOnDelete();

            $table->foreign('perusahaan_id')
                ->references('id')->on('tb_perusahaan')
                ->restrictOnDelete();

            $table->foreign('locked_by')
                ->references('id')->on('tb_users')
                ->nullOnDelete();

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
        Schema::dropIfExists('tb_ending_balance_ap');
    }
};
