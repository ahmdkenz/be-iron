<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_ap_shz360_po_imports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_po_id')->unique();
            $table->string('kode_po', 50);
            $table->date('tanggal_po')->nullable();
            $table->unsignedBigInteger('source_supplier_id')->nullable();
            $table->foreignId('vendor_ap_id')->nullable()->constrained('tb_vendor_ap')->nullOnDelete();
            $table->string('status_po', 40)->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('ongkir', 15, 2)->default(0);
            $table->decimal('diskon', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->dateTime('source_updated_at')->nullable();
            $table->string('source_hash', 64)->nullable();
            // NEED_MAPPING -> READY_FOR_AP -> CONVERTED, atau IGNORED / ERROR kapan saja
            $table->string('import_status', 20)->default('NEED_MAPPING');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index('kode_po');
            $table->index('import_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_ap_shz360_po_imports');
    }
};
