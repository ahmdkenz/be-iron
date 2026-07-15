<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_tagihan_ap', function (Blueprint $table) {
            $table->string('source_system', 20)->default('MANUAL')->after('vendor_ap_id');
            // Menunjuk ke ID staging tb_ap_shz360_po_imports / _receipt_imports (bukan ID mentah SHZ360),
            // supaya tidak ambigu dengan source_po_id/source_receipt_id yang sudah dipakai di tabel staging itu sendiri.
            $table->foreignId('ap_shz360_po_import_id')->nullable()->after('source_system')
                ->constrained('tb_ap_shz360_po_imports')->nullOnDelete();
            $table->foreignId('ap_shz360_receipt_import_id')->nullable()->after('ap_shz360_po_import_id')
                ->constrained('tb_ap_shz360_receipt_imports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_tagihan_ap', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ap_shz360_receipt_import_id');
            $table->dropConstrainedForeignId('ap_shz360_po_import_id');
            $table->dropColumn('source_system');
        });
    }
};
