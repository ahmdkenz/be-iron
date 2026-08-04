<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_opening_balance_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('original_filename', 255)->nullable();
            $table->string('file_path', 500)->nullable();
            $table->boolean('is_csv')->default(false);

            $table->enum('status', ['queued', 'processing', 'completed', 'failed'])
                ->default('queued');

            $table->unsignedInteger('total_ob')->default(0);
            $table->unsignedInteger('processed_ob')->default(0);
            $table->unsignedInteger('inserted_ob')->default(0);
            $table->unsignedInteger('skipped_ob')->default(0);
            $table->unsignedInteger('failed_ob')->default(0);

            $table->unsignedInteger('total_detail')->default(0);
            $table->unsignedInteger('inserted_detail')->default(0);

            $table->unsignedInteger('total_item')->default(0);
            $table->unsignedInteger('inserted_item')->default(0);

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
        Schema::dropIfExists('tb_opening_balance_import_batches');
    }
};
