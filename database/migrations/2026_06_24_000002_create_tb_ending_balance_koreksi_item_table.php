<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_ending_balance_koreksi_item', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ending_balance_koreksi_id');
            $table->unsignedBigInteger('invoice_item_id');

            // Snapshot nilai lama (dari invoice asli, tidak berubah)
            $table->string('nama_barang');
            $table->decimal('qty_lama', 10, 3);
            $table->decimal('harga_satuan_lama', 15, 2);
            $table->decimal('subtotal_lama', 15, 2);

            // Nilai koreksi yang diusulkan
            $table->decimal('qty_baru', 10, 3);
            $table->decimal('harga_satuan_baru', 15, 2);
            $table->decimal('subtotal_baru', 15, 2);

            // Selisih = subtotal_baru - subtotal_lama (positif=tambah, negatif=kurang)
            $table->decimal('selisih', 15, 2);

            $table->timestamps();

            $table->foreign('ending_balance_koreksi_id')
                ->references('id')->on('tb_ending_balance_koreksi')
                ->cascadeOnDelete();

            $table->foreign('invoice_item_id')
                ->references('id')->on('tb_invoice_item')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_ending_balance_koreksi_item');
    }
};
