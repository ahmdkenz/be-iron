<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('tb_bulk_print_tokens');
    }

    public function down(): void
    {
        Schema::create('tb_bulk_print_tokens', function (Blueprint $table) {
            $table->uuid('token')->primary();
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
        });
    }
};
