<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_invoice_approval_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('tb_invoice')->cascadeOnDelete();
            $table->string('action', 20);
            $table->foreignId('actor_id')->nullable()->constrained('tb_users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'created_at'], 'tb_invoice_approval_logs_invoice_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_invoice_approval_logs');
    }
};
