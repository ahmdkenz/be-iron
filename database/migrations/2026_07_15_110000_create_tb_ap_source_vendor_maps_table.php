<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_ap_source_vendor_maps', function (Blueprint $table) {
            $table->id();
            $table->string('source_system', 30)->default('SHZ360');
            $table->unsignedBigInteger('source_supplier_id');
            $table->string('source_supplier_code', 50)->nullable();
            $table->string('source_supplier_name', 150)->nullable();
            $table->foreignId('vendor_ap_id')->nullable()->constrained('tb_vendor_ap')->nullOnDelete();
            $table->foreignId('perusahaan_id')->nullable()->constrained('tb_perusahaan')->nullOnDelete();
            $table->string('status', 20)->default('NEED_MAPPING');
            $table->json('raw_payload')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['source_system', 'source_supplier_id'], 'tb_ap_source_vendor_maps_source_unique');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_ap_source_vendor_maps');
    }
};
