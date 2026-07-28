<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_bank_statement_import_batch', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('bank_type', 20);
            $table->string('original_filename');
            $table->string('file_path', 500)->nullable();

            // status: kasar, dipakai FE untuk berhenti polling (processing mencakup
            // semua sub-fase parsing/validating/checking_overlap/saving/auto_matching).
            $table->enum('status', ['queued', 'processing', 'needs_confirmation', 'completed', 'failed'])
                  ->default('queued');

            // phase: rinci, dipakai FE untuk teks progress yang ditampilkan ke user.
            $table->string('phase', 30)->default('queued');

            $table->text('message')->nullable();

            $table->date('periode_awal')->nullable();
            $table->date('periode_akhir')->nullable();

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('inserted_rows')->default(0);
            $table->unsignedInteger('matched_rows')->default(0);
            $table->unsignedInteger('unmatched_rows')->default(0);
            $table->unsignedInteger('ignored_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->decimal('total_kredit', 15, 2)->default(0);

            $table->unsignedBigInteger('bank_statement_id')->nullable();
            $table->boolean('force_replace')->default(false);

            $table->json('overlaps')->nullable();
            $table->json('errors')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index('user_id');
            $table->index('status');

            $table->foreign('user_id')
                ->references('id')->on('tb_users')
                ->cascadeOnDelete();

            $table->foreign('bank_statement_id')
                ->references('id')->on('tb_bank_statement')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_bank_statement_import_batch');
    }
};
