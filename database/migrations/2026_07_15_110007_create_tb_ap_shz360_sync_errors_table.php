<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_ap_shz360_sync_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_run_id')->constrained('tb_ap_shz360_sync_runs')->cascadeOnDelete();
            $table->string('source_type', 20); // PO | TERIMA_PO
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_ap_shz360_sync_errors');
    }
};
