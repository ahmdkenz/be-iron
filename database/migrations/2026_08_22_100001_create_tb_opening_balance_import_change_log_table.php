<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_opening_balance_import_change_log', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id');

            $table->unsignedInteger('row_number');

            // 'ditambahkan' | 'diperbarui' | 'dilewati' | 'gagal' — 'diperbarui' adalah
            // jalur resolusi konflik otomatis di OpeningBalanceImportService: OB lama
            // dihapus lalu digantikan data baru (bukan update in-place) saat data yang
            // diimport berbeda dari OB lama pada klien+tanggal cutover yang sama.
            $table->string('change_type', 20);

            $table->json('data_sebelum')->nullable();
            $table->json('data_baru')->nullable();
            $table->text('message')->nullable();

            // Sama seperti tb_import_master_change_log: gabungan text data_sebelum +
            // data_baru + message, dipakai untuk LIKE search murah tanpa JSON_EXTRACT.
            $table->string('search_text', 500)->nullable();

            $table->timestamps();

            $table->foreign('batch_id')
                ->references('id')->on('tb_opening_balance_import_batches')
                ->cascadeOnDelete();

            $table->index('batch_id');
            $table->index(['batch_id', 'change_type']);

            // TIDAK ada kolom entity_type (beda dari tb_import_master_change_log): Import
            // Master Opening Balance itu single-entity (cuma Opening Balance AR), sengaja
            // tidak dibuat generic/dipakai bersama Master Data — lihat catatan di plan.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_opening_balance_import_change_log');
    }
};
