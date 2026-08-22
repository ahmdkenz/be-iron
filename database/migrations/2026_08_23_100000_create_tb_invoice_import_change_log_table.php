<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_invoice_import_change_log', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id');

            // Baris Excel pertama dari grup invoice ini (InvoiceImportGroup::first_line) —
            // 1 grup = 1 calon invoice yang bisa mencakup banyak baris item.
            $table->unsignedInteger('row_number');

            // HANYA 'ditambahkan' | 'diperbarui' | 'gagal' — sengaja TIDAK ada 'dilewati',
            // mengikuti pola tb_import_master_change_log & tb_opening_balance_import_change_log
            // (lihat migration 2026_08_22_120000 yang menghapus baris 'dilewati' di sana).
            // Grup REJECTED (invoice sudah LUNAS / periode terkunci di Ending Balance) dan
            // UNCHANGED tidak pernah dicatat di sini — jumlahnya cukup terlihat lewat
            // counter cnt_rejected/cnt_unchanged pada batch.
            $table->string('change_type', 20);

            $table->json('data_sebelum')->nullable();
            $table->json('data_baru')->nullable();
            $table->text('message')->nullable();

            // Sama seperti dua tabel change log lainnya: gabungan text data_sebelum +
            // data_baru + message, dipakai untuk LIKE search murah tanpa JSON_EXTRACT.
            $table->string('search_text', 500)->nullable();

            $table->timestamps();

            $table->foreign('batch_id')
                ->references('id')->on('tb_invoice_import_batches')
                ->cascadeOnDelete();

            $table->index('batch_id');
            $table->index(['batch_id', 'change_type']);

            // TIDAK ada kolom entity_type (mirror tb_opening_balance_import_change_log):
            // Import Master Invoice single-entity, jadi tabel Riwayat Perubahan-nya juga
            // tidak punya kolom/filter "Sheet".
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_invoice_import_change_log');
    }
};
