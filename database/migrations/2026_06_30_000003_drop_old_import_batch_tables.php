<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('tb_investor_import_batch');
        Schema::dropIfExists('tb_klien_ar_import_batch');
        Schema::dropIfExists('tb_resto_import_batch');
        Schema::dropIfExists('tb_barang_import_batch');
        Schema::dropIfExists('tb_invoice_import_batch');
    }

    public function down(): void
    {
        // Tables removed intentionally — not restored on rollback.
        // Import tracking now unified in tb_import_master_batch.
    }
};
