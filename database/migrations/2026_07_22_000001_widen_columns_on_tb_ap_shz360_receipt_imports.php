<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Sumber SHZ360 kadang mengirim no_invoice/no_surat_jalan/no_faktur_pajak lebih
// panjang dari 50 karakter, menyebabkan error "Data too long for column" saat sync
// dan baris tersebut gagal ter-staging. Diperlebar ke 100 karakter dengan aman
// untuk data eksisting (MODIFY tidak mengubah data yang sudah ada).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tb_ap_shz360_receipt_imports MODIFY no_invoice VARCHAR(100) NULL');
        DB::statement('ALTER TABLE tb_ap_shz360_receipt_imports MODIFY no_surat_jalan VARCHAR(100) NULL');
        DB::statement('ALTER TABLE tb_ap_shz360_receipt_imports MODIFY no_faktur_pajak VARCHAR(100) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tb_ap_shz360_receipt_imports MODIFY no_invoice VARCHAR(50) NULL');
        DB::statement('ALTER TABLE tb_ap_shz360_receipt_imports MODIFY no_surat_jalan VARCHAR(50) NULL');
        DB::statement('ALTER TABLE tb_ap_shz360_receipt_imports MODIFY no_faktur_pajak VARCHAR(50) NULL');
    }
};
