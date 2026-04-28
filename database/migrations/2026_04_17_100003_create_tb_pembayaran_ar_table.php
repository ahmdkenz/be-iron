<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_pembayaran_ar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('tb_invoice')->cascadeOnDelete();
            $table->date('tanggal_pembayaran');
            $table->decimal('jumlah_pembayaran', 15, 2);
            $table->enum('metode_pembayaran', ['TRANSFER', 'CASH', 'GIRO'])->default('TRANSFER');
            $table->string('no_referensi')->nullable();
            $table->text('keterangan')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_pembayaran_ar');
    }
};
