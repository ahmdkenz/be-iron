<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_pembayaran_ap', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tagihan_ap_id')->constrained('tb_tagihan_ap')->cascadeOnDelete();
            $table->date('tanggal_pembayaran');
            $table->decimal('jumlah_pembayaran', 15, 2);
            $table->enum('metode_pembayaran', ['TRANSFER', 'CASH', 'GIRO'])->default('TRANSFER');
            $table->string('no_referensi')->nullable();
            $table->text('keterangan')->nullable();
            $table->unsignedBigInteger('sumber_pembayaran_ap_id')->nullable();
            $table->boolean('dibuat_dari_rekonsiliasi')->default(false);
            $table->string('bukti_file_name')->nullable();
            $table->unsignedBigInteger('bukti_file_size')->nullable();
            $table->string('bukti_mime_type')->nullable();
            $table->timestamp('bukti_uploaded_at')->nullable();
            $table->string('bukti_disk')->nullable();
            $table->string('bukti_path')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique('no_referensi', 'uniq_pembayaran_ap_no_referensi');
            $table->foreign('sumber_pembayaran_ap_id')
                  ->references('id')->on('tb_pembayaran_ap')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_pembayaran_ap');
    }
};
