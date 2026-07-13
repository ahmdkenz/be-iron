<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_tagihan_ap_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tagihan_ap_id')->constrained('tb_tagihan_ap')->cascadeOnDelete();
            $table->foreignId('barang_id')->nullable()->constrained('tb_barang')->nullOnDelete();
            $table->string('kode_barang')->nullable();
            $table->string('nama_barang');
            $table->decimal('qty', 10, 3)->default(0);
            $table->string('satuan', 20)->nullable();
            $table->decimal('harga_satuan', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->string('keterangan')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_tagihan_ap_item');
    }
};
