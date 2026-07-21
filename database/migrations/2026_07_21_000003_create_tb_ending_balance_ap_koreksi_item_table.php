<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_ending_balance_ap_koreksi_item', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ending_balance_ap_koreksi_id');
            $table->unsignedBigInteger('tagihan_ap_item_id');

            // Snapshot nilai lama (dari item tagihan asli, tidak berubah)
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

            $table->foreign('ending_balance_ap_koreksi_id', 'eb_ap_koreksi_item_koreksi_id_foreign')
                ->references('id')->on('tb_ending_balance_ap_koreksi')
                ->cascadeOnDelete();

            $table->foreign('tagihan_ap_item_id')
                ->references('id')->on('tb_tagihan_ap_item')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_ending_balance_ap_koreksi_item');
    }
};
