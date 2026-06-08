<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('tb_invoice_delivery_detail');
    }

    public function down(): void
    {
        Schema::create('tb_invoice_delivery_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('tb_invoice')->cascadeOnDelete();
            $table->string('no_invoice_resto')->nullable();
            $table->date('tanggal_kirim')->nullable();
            $table->string('nama_resto')->nullable();
            $table->string('stokis')->nullable();
            $table->string('kode_barang', 50)->nullable();
            $table->string('nama_barang', 150);
            $table->decimal('qty', 10, 3);
            $table->string('satuan', 20)->nullable();
            $table->decimal('harga_satuan', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->timestamps();
        });
    }
};
