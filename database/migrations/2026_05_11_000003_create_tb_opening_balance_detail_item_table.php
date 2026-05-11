<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_opening_balance_detail_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ob_detail_id')
                  ->constrained('tb_opening_balance_detail')
                  ->cascadeOnDelete();
            $table->foreignId('barang_id')
                  ->nullable()
                  ->nullOnDelete()
                  ->constrained('tb_barang');
            $table->string('nama_barang', 255);
            $table->decimal('qty', 10, 3)->default(0);
            $table->string('satuan', 20)->nullable();
            $table->decimal('harga_satuan', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->string('keterangan', 500)->nullable();
            $table->timestamps();

            $table->index('ob_detail_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_opening_balance_detail_item');
    }
};
