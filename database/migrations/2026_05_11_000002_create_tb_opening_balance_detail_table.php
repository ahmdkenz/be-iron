<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_opening_balance_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')
                  ->constrained('tb_invoice')
                  ->cascadeOnDelete();
            $table->string('no_invoice_asal', 100);
            $table->date('tanggal_invoice_asal');
            $table->string('deskripsi', 255);
            $table->decimal('jumlah_tagihan_asal', 15, 2)->default(0);
            $table->decimal('sisa_tagihan_asal', 15, 2)->default(0);
            $table->string('keterangan', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('tanggal_invoice_asal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_opening_balance_detail');
    }
};
