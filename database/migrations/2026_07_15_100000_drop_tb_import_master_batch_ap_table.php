<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('tb_import_master_batch_ap');
    }

    public function down(): void
    {
        Schema::create('tb_import_master_batch_ap', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('file_path', 500)->nullable();

            $table->enum('status', ['queued', 'processing', 'completed', 'failed'])
                  ->default('queued');

            $table->unsignedInteger('vendor_total')->default(0);
            $table->unsignedInteger('vendor_processed')->default(0);
            $table->unsignedInteger('vendor_inserted')->default(0);
            $table->unsignedInteger('vendor_updated')->default(0);
            $table->unsignedInteger('vendor_skipped')->default(0);
            $table->unsignedInteger('vendor_failed')->default(0);

            $table->unsignedInteger('tagihan_total')->default(0);
            $table->unsignedInteger('tagihan_processed')->default(0);
            $table->unsignedInteger('tagihan_inserted')->default(0);
            $table->unsignedInteger('tagihan_skipped')->default(0);
            $table->unsignedInteger('tagihan_failed')->default(0);

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
};
