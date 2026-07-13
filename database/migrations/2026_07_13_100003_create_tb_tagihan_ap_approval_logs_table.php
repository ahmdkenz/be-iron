<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_tagihan_ap_approval_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tagihan_ap_id')->constrained('tb_tagihan_ap')->cascadeOnDelete();
            $table->string('action', 20);
            $table->foreignId('actor_id')->nullable()->constrained('tb_users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tagihan_ap_id', 'created_at'], 'tb_tagihan_ap_approval_logs_tagihan_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_tagihan_ap_approval_logs');
    }
};
