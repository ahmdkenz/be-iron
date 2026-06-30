<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_import_master_batch', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('file_path', 500)->nullable();

            $table->enum('status', ['queued', 'processing', 'completed', 'failed'])
                  ->default('queued');

            // Sheet MASTER DATA — progress
            $table->unsignedInteger('master_total')->default(0);
            $table->unsignedInteger('master_processed')->default(0);

            // Sheet MASTER DATA — hasil per entitas
            $table->unsignedInteger('investor_inserted')->default(0);
            $table->unsignedInteger('investor_updated')->default(0);
            $table->unsignedInteger('investor_failed')->default(0);
            $table->unsignedInteger('resto_inserted')->default(0);
            $table->unsignedInteger('resto_updated')->default(0);
            $table->unsignedInteger('resto_failed')->default(0);
            $table->unsignedInteger('klien_inserted')->default(0);
            $table->unsignedInteger('klien_updated')->default(0);
            $table->unsignedInteger('klien_failed')->default(0);

            // Sheet MASTER BARANG — progress
            $table->unsignedInteger('barang_total')->default(0);
            $table->unsignedInteger('barang_processed')->default(0);

            // Sheet MASTER BARANG — hasil
            $table->unsignedInteger('barang_inserted')->default(0);
            $table->unsignedInteger('barang_updated')->default(0);
            $table->unsignedInteger('barang_failed')->default(0);

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
        Schema::dropIfExists('tb_import_master_batch');
    }
};
