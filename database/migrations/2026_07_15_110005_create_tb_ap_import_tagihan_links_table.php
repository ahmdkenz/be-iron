<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_ap_import_tagihan_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tagihan_ap_id')->constrained('tb_tagihan_ap')->cascadeOnDelete();
            $table->foreignId('po_import_id')->constrained('tb_ap_shz360_po_imports')->restrictOnDelete();
            // Unique: satu penerimaan barang cuma boleh dikonversi jadi tagihan sekali (cegah dobel tagih).
            $table->foreignId('receipt_import_id')->unique()->constrained('tb_ap_shz360_receipt_imports')->restrictOnDelete();
            $table->decimal('amount_allocated', 15, 2)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_ap_import_tagihan_links');
    }
};
