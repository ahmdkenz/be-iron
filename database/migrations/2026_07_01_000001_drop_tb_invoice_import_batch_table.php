<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('tb_invoice_import_batch');
    }

    public function down(): void
    {
        // Table removal is irreversible; the old import flow uses tb_import_master_batch.
    }
};
