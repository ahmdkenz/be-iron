<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_ap_shz360_po_import_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('po_import_id')->constrained('tb_ap_shz360_po_imports')->cascadeOnDelete();
            $table->unsignedBigInteger('source_po_item_id')->nullable();
            $table->unsignedBigInteger('source_barang_id')->nullable();
            $table->string('kode_barang', 50)->nullable();
            $table->string('nama_barang', 150);
            $table->string('satuan', 30)->nullable();
            $table->decimal('qty_po', 15, 4)->default(0);
            $table->decimal('harga', 15, 2)->default(0);
            $table->decimal('ppn', 5, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_ap_shz360_po_import_items');
    }
};
