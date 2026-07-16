<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // tb_pembayaran_ar.invoice_id tidak punya FOREIGN KEY constraint aktif di DB ini
    // (hanya index biasa peninggalan `tb_pembayaran_ar_invoice_id_foreign`), jadi cukup
    // ubah nullability kolom tanpa drop/re-add foreign key.
    public function up(): void
    {
        DB::statement('ALTER TABLE tb_pembayaran_ar MODIFY invoice_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tb_pembayaran_ar MODIFY invoice_id BIGINT UNSIGNED NOT NULL');
    }
};
