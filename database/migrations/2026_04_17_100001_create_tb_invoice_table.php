<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_invoice', function (Blueprint $table) {
            $table->id();
            $table->string('no_invoice', 50)->unique();
            $table->date('tanggal_invoice');
            $table->date('periode_awal');
            $table->date('periode_akhir');
            $table->foreignId('klien_ar_id')->constrained('tb_klien_ar')->restrictOnDelete();
            $table->foreignId('perusahaan_id')->constrained('tb_perusahaan')->restrictOnDelete();
            $table->foreignId('karyawan_id')->constrained('tb_karyawan')->restrictOnDelete();
            $table->string('no_surat_jalan')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tagihan_periode_sebelumnya', 15, 2)->default(0);
            $table->decimal('total_tagihan', 15, 2)->default(0);
            $table->decimal('total_pembayaran', 15, 2)->default(0);
            $table->decimal('sisa_tagihan', 15, 2)->default(0);
            $table->enum('status', ['DRAFT', 'TERKIRIM', 'SEBAGIAN', 'LUNAS'])->default('DRAFT');
            $table->boolean('is_opening_balance')->default(false);
            $table->text('keterangan')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_invoice');
    }
};
