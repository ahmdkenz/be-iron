<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_import_master_change_log', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id');

            // 'investor' | 'resto' | 'klien_ar' | 'barang' — dipakai sbg kolom/filter "Sheet" di UI,
            // BUKAN nama sheet Excel literal (yang cuma ada 2: MASTER DATA & MASTER BARANG).
            $table->string('entity_type', 20);

            $table->unsignedInteger('row_number');

            // 'ditambahkan' | 'diperbarui' | 'dilewati' | 'gagal'
            $table->string('change_type', 20);

            $table->json('data_sebelum')->nullable();
            $table->json('data_baru')->nullable();
            $table->text('message')->nullable();

            // Gabungan text data_sebelum + data_baru + message, ditulis saat insert — dipakai untuk
            // LIKE search murah tanpa JSON_EXTRACT per baris (tidak ada precedent JSON search lain
            // di codebase ini).
            $table->string('search_text', 500)->nullable();

            $table->timestamps();

            $table->foreign('batch_id')
                ->references('id')->on('tb_import_master_batch')
                ->cascadeOnDelete();

            $table->index('batch_id');
            $table->index(['batch_id', 'entity_type']);
            $table->index(['batch_id', 'change_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_import_master_change_log');
    }
};
