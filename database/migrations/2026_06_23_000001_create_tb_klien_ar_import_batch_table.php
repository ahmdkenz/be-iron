<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_klien_ar_import_batch', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('file_path', 500)->nullable();

            // queued → processing → completed | failed
            $table->enum('status', ['queued', 'processing', 'completed', 'failed'])
                  ->default('queued');

            // Progress (denominator = total baris mentah dari file)
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);

            // Hasil akhir (semantik bisnis) — upsert: insert + update
            $table->unsignedInteger('total_data')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->json('errors')->nullable();
            $table->text('message')->nullable();

            $table->timestamps();

            $table->index('user_id');
            $table->index('status');

            $table->foreign('user_id')
                ->references('id')->on('tb_users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_klien_ar_import_batch');
    }
};
