<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Staging item invoice per baris Excel. Dipertahankan sampai batch dihapus
        // supaya tabel review bisa menampilkan detail item lama vs baru tanpa
        // membaca ulang file.
        Schema::create('tb_invoice_import_rows', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id');
            $table->unsignedBigInteger('group_id')->nullable();
            $table->unsignedInteger('row_number');

            $table->string('no_invoice_resto')->nullable();
            $table->string('kode_resto', 100)->nullable();
            $table->string('nama_resto')->nullable();
            $table->string('kode_barang', 100)->nullable();
            $table->string('nama_barang')->nullable();
            $table->unsignedBigInteger('barang_id')->nullable();
            $table->decimal('qty', 15, 2)->default(0);
            $table->string('satuan', 50)->nullable();
            $table->decimal('harga_satuan', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->text('error')->nullable();

            $table->timestamps();

            $table->foreign('batch_id')
                ->references('id')->on('tb_invoice_import_batches')
                ->cascadeOnDelete();

            $table->foreign('group_id')
                ->references('id')->on('tb_invoice_import_groups')
                ->nullOnDelete();

            $table->index('batch_id');
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_invoice_import_rows');
    }
};
