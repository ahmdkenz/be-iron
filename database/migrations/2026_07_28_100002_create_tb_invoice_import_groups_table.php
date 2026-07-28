<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1 grup = 1 calon invoice (tipe_invoice + klien_ar_id + tanggal_invoice),
        // yaitu unit terkecil yang diklasifikasi & diputuskan user.
        Schema::create('tb_invoice_import_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id');
            $table->string('group_key', 191);

            // Identitas dari file
            $table->string('tipe_invoice', 5)->nullable();
            $table->string('nama_klien')->nullable();
            $table->string('kode_resto', 100)->nullable();
            $table->date('tanggal_invoice')->nullable();
            $table->date('tanggal_jatuh_tempo')->nullable();
            $table->string('no_surat_jalan')->nullable();
            $table->text('keterangan')->nullable();
            $table->unsignedInteger('first_line')->default(0);
            $table->unsignedInteger('item_count')->default(0);

            // Hasil resolve
            $table->unsignedBigInteger('klien_ar_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('no_invoice')->nullable();

            // NEW_INVOICE | UNCHANGED | SAFE_UPDATE | REVIEW_REQUIRED | REJECTED
            $table->string('classification', 20)->default('REJECTED');
            $table->text('reason')->nullable();
            // Daftar alasan kenapa invoice dianggap berisiko (PEMBAYARAN, BANK_MATCHED, …)
            $table->json('risk_flags')->nullable();
            $table->json('header_diff')->nullable();

            // Nilai untuk kandidat penyesuaian
            $table->decimal('total_lama', 15, 2)->default(0);
            $table->decimal('total_baru', 15, 2)->default(0);
            $table->decimal('selisih', 15, 2)->default(0);
            // CREDIT_NOTE | DEBIT_NOTE | METADATA | null
            $table->string('adjustment_type', 20)->nullable();

            // Keputusan user atas baris REVIEW_REQUIRED
            // PENDING | SUBMITTED | DISMISSED
            $table->string('review_status', 20)->nullable();
            $table->unsignedBigInteger('koreksi_id')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('review_message')->nullable();

            // Hasil "Proses Data Aman"
            // PENDING | APPLIED | SKIPPED | FAILED
            $table->string('apply_status', 20)->nullable();
            $table->text('apply_message')->nullable();

            $table->timestamps();

            $table->foreign('batch_id')
                ->references('id')->on('tb_invoice_import_batches')
                ->cascadeOnDelete();

            $table->index('batch_id');
            $table->index(['batch_id', 'classification']);
            $table->index('invoice_id');
            $table->unique(['batch_id', 'group_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_invoice_import_groups');
    }
};
