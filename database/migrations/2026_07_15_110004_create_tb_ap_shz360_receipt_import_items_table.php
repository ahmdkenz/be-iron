<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_ap_shz360_receipt_import_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_import_id')->constrained('tb_ap_shz360_receipt_imports')->cascadeOnDelete();
            $table->foreignId('po_item_import_id')->nullable()->constrained('tb_ap_shz360_po_import_items')->nullOnDelete();
            $table->unsignedBigInteger('source_receipt_item_id')->nullable();
            $table->unsignedBigInteger('source_barang_id')->nullable();
            $table->string('kode_barang', 50)->nullable();
            $table->string('nama_barang', 150);
            $table->string('satuan', 30)->nullable();
            $table->string('status_detail_terima_po', 30)->nullable();
            $table->decimal('qty_diterima', 15, 4)->default(0);
            $table->decimal('qty_tolak', 15, 4)->default(0);
            $table->text('keterangan_tolak')->nullable();
            $table->decimal('harga', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_ap_shz360_receipt_import_items');
    }
};
