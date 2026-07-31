<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_pembayaran_ar_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pembayaran_ar_id')->constrained('tb_pembayaran_ar')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('tb_invoice')->restrictOnDelete();
            $table->foreignId('klien_ar_id')->constrained('tb_klien_ar')->restrictOnDelete();
            $table->decimal('jumlah_dialokasikan', 15, 2)->default(0);
            $table->decimal('sisa_sebelum', 15, 2)->default(0);
            $table->decimal('sisa_sesudah', 15, 2)->default(0);
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('klien_ar_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_pembayaran_ar_items');
    }
};
