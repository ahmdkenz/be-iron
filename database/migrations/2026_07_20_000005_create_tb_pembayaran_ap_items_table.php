<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_pembayaran_ap_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pembayaran_ap_id')->constrained('tb_pembayaran_ap')->cascadeOnDelete();
            $table->foreignId('tagihan_ap_id')->constrained('tb_tagihan_ap')->restrictOnDelete();
            $table->foreignId('vendor_ap_id')->constrained('tb_vendor_ap')->restrictOnDelete();
            $table->decimal('jumlah_dialokasikan', 15, 2)->default(0);
            $table->decimal('sisa_sebelum', 15, 2)->default(0);
            $table->decimal('sisa_sesudah', 15, 2)->default(0);
            $table->timestamps();

            $table->index('tagihan_ap_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_pembayaran_ap_items');
    }
};
