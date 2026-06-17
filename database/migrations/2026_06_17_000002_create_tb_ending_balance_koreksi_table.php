<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_ending_balance_koreksi', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ending_balance_id');
            $table->unsignedBigInteger('klien_ar_id');

            // Data koreksi
            $table->decimal('nilai_koreksi', 15, 2);  // positif = tambah, negatif = kurang
            $table->text('alasan_koreksi');
            $table->string('dokumen_url', 500)->nullable();

            // Approval sequential: PENDING_SPV → PENDING_MANAGER → APPROVED / REJECTED
            $table->enum('status', ['PENDING_SPV', 'PENDING_MANAGER', 'APPROVED', 'REJECTED'])
                  ->default('PENDING_SPV');

            // SPV approval
            $table->unsignedBigInteger('spv_id')->nullable();
            $table->text('spv_note')->nullable();
            $table->timestamp('spv_actioned_at')->nullable();

            // Manager approval
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->text('manager_note')->nullable();
            $table->timestamp('manager_actioned_at')->nullable();

            $table->unsignedBigInteger('submitted_by');
            $table->timestamp('submitted_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index('ending_balance_id');
            $table->index('klien_ar_id');
            $table->index('status');

            $table->foreign('ending_balance_id')
                ->references('id')->on('tb_ending_balance')
                ->cascadeOnDelete();

            $table->foreign('klien_ar_id')
                ->references('id')->on('tb_klien_ar')
                ->restrictOnDelete();

            $table->foreign('spv_id')
                ->references('id')->on('tb_users')
                ->nullOnDelete();

            $table->foreign('manager_id')
                ->references('id')->on('tb_users')
                ->nullOnDelete();

            $table->foreign('submitted_by')
                ->references('id')->on('tb_users')
                ->restrictOnDelete();

            $table->foreign('updated_by')
                ->references('id')->on('tb_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_ending_balance_koreksi');
    }
};
