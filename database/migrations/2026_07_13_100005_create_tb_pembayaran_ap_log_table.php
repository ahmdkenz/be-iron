<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_pembayaran_ap_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pembayaran_ap_id')
                  ->constrained('tb_pembayaran_ap')
                  ->cascadeOnDelete();
            $table->enum('aksi', ['DIBUAT', 'DIUBAH', 'DIHAPUS']);
            $table->foreignId('actor_id')
                  ->nullable()
                  ->constrained('tb_users')
                  ->nullOnDelete();
            $table->json('data_sebelum')->nullable();
            $table->json('data_sesudah')->nullable();
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->index(['pembayaran_ap_id', 'created_at'], 'idx_pem_ap_log_id_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_pembayaran_ap_log');
    }
};
