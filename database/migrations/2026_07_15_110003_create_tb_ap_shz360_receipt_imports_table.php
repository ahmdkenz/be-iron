<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_ap_shz360_receipt_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('po_import_id')->nullable()->constrained('tb_ap_shz360_po_imports')->nullOnDelete();
            $table->unsignedBigInteger('source_receipt_id')->unique();
            $table->string('kode_receipt', 50);
            $table->date('tanggal_receipt')->nullable();
            $table->string('no_invoice', 50)->nullable();
            $table->string('no_surat_jalan', 50)->nullable();
            $table->string('no_faktur_pajak', 50)->nullable();
            // NEW -> READY_FOR_AP (setelah vendor po_import termapping) -> CONVERTED, atau IGNORED / ERROR
            $table->string('import_status', 20)->default('NEW');
            $table->dateTime('source_updated_at')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index('kode_receipt');
            $table->index('import_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_ap_shz360_receipt_imports');
    }
};
