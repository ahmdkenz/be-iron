<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Batch import invoice (tab "Import Master Invoice"). Berbeda dengan
        // tb_import_master_batch yang langsung menulis data: batch ini berhenti di
        // status "awaiting_review" setelah klasifikasi, dan baru menulis invoice
        // ketika user menekan "Proses Data Aman".
        Schema::create('tb_invoice_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('original_filename')->nullable();
            $table->string('file_path')->nullable();

            // queued | parsing | classifying | awaiting_review | processing | completed | failed
            $table->string('status', 30)->default('queued');
            // Fase detail untuk progress bar FE (parsing_rows, classifying, applying, finalizing, …)
            $table->string('phase', 30)->nullable();
            $table->text('message')->nullable();

            // Progress parsing/klasifikasi
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('parsed_rows')->default(0);
            $table->unsignedInteger('total_groups')->default(0);
            $table->unsignedInteger('classified_groups')->default(0);

            // Hasil klasifikasi
            $table->unsignedInteger('cnt_new')->default(0);
            $table->unsignedInteger('cnt_unchanged')->default(0);
            $table->unsignedInteger('cnt_safe_update')->default(0);
            $table->unsignedInteger('cnt_review_required')->default(0);
            $table->unsignedInteger('cnt_rejected')->default(0);
            $table->unsignedInteger('cnt_cn_candidate')->default(0);
            $table->unsignedInteger('cnt_dn_candidate')->default(0);
            $table->unsignedInteger('cnt_metadata_candidate')->default(0);

            // Hasil "Proses Data Aman"
            $table->unsignedInteger('applied_total')->default(0);
            $table->unsignedInteger('applied_processed')->default(0);
            $table->unsignedInteger('applied_inserted')->default(0);
            $table->unsignedInteger('applied_updated')->default(0);
            $table->unsignedInteger('applied_skipped')->default(0);
            $table->unsignedInteger('applied_failed')->default(0);

            // Hasil pengajuan penyesuaian (CN/DN)
            $table->unsignedInteger('adjustment_submitted')->default(0);
            $table->unsignedInteger('adjustment_dismissed')->default(0);

            $table->json('errors')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('classified_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_invoice_import_batches');
    }
};
