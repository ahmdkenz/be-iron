<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buang sisa kolom fitur "penyesuaian otomatis Credit Note / Debit Note" pada staging
 * Import Master Invoice. Fitur itu dihapus: import tidak pernah lagi mengajukan koreksi
 * Ending Balance sendiri (classifyGroup() tidak lagi menghasilkan REVIEW_REQUIRED, dan
 * submitAdjustments()/createKoreksiForGroup() sudah dihapus dari InvoiceImportService).
 * CN/DN sekarang 100% dibuat manual lewat menu Ending Balance → Koreksi.
 *
 * Aman dihapus: kolom-kolom ini hanya dibaca/ditulis di dalam stack invoice-import.
 * Relasi ke koreksi bersifat SATU ARAH (tb_invoice_import_groups.koreksi_id →
 * tb_ending_balance_koreksi.id), jadi menghapusnya TIDAK membuat record koreksi Ending
 * Balance jadi yatim — record koreksi berdiri sendiri dan tidak menyimpan balik id grup.
 * Kedua tabel ini juga transient (di-wipe InvoiceImportBatch::cancel(), grup batch lama
 * tidak pernah dibaca ulang setelah batch selesai).
 *
 * cnt_review_required SENGAJA dipertahankan: ia bagian dari satu set counter klasifikasi
 * (cnt_new/cnt_unchanged/cnt_safe_update/cnt_review_required/cnt_rejected) dan sekarang
 * cuma akan bernilai 0 — tidak mengganggu apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_invoice_import_groups', function (Blueprint $table) {
            $table->dropColumn([
                'adjustment_type',
                'review_status',
                'koreksi_id',
                'decided_by',
                'decided_at',
                'review_message',
            ]);
        });

        Schema::table('tb_invoice_import_batches', function (Blueprint $table) {
            $table->dropColumn([
                'cnt_cn_candidate',
                'cnt_dn_candidate',
                'cnt_metadata_candidate',
                'adjustment_submitted',
                'adjustment_dismissed',
            ]);
        });
    }

    public function down(): void
    {
        // Definisi dikembalikan persis seperti migration pembuat aslinya
        // (2026_07_28_100002 & 2026_07_28_100001). Posisi kolom akan berada di akhir
        // tabel, bukan di urutan semula — tidak berpengaruh secara fungsional.
        Schema::table('tb_invoice_import_groups', function (Blueprint $table) {
            $table->string('adjustment_type', 20)->nullable();
            $table->string('review_status', 20)->nullable();
            $table->unsignedBigInteger('koreksi_id')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('review_message')->nullable();
        });

        Schema::table('tb_invoice_import_batches', function (Blueprint $table) {
            $table->unsignedInteger('cnt_cn_candidate')->default(0);
            $table->unsignedInteger('cnt_dn_candidate')->default(0);
            $table->unsignedInteger('cnt_metadata_candidate')->default(0);
            $table->unsignedInteger('adjustment_submitted')->default(0);
            $table->unsignedInteger('adjustment_dismissed')->default(0);
        });
    }
};
