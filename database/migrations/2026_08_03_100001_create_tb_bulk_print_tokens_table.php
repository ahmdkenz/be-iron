<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kunci pencarian pendek (UUID) untuk link publik bulk-print investor —
        // payload (invoice_ids + investor/klien anchor id) disimpan di DB
        // supaya panjang token konstan, tidak lagi linear terhadap jumlah
        // invoice yang digabung (lihat App\Models\BulkPrintToken).
        Schema::create('tb_bulk_print_tokens', function (Blueprint $table) {
            $table->uuid('token')->primary();
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_bulk_print_tokens');
    }
};
