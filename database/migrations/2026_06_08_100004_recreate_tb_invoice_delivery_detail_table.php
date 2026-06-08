<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_invoice_delivery_detail', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->foreign('invoice_id')->references('id')->on('tb_invoice')->onDelete('cascade');
            $table->string('no_invoice_resto')->nullable();
            $table->string('kode_resto')->nullable();
            $table->string('nama_resto')->nullable();
            $table->string('kode_barang')->nullable();
            $table->string('nama_barang');
            $table->decimal('qty', 12, 2);
            $table->string('satuan')->nullable();
            $table->decimal('harga_satuan', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_invoice_delivery_detail');
    }
};
