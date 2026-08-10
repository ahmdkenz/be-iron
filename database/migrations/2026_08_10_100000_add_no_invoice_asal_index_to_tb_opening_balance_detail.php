<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // no_invoice_asal (VARCHAR 100) dipakai InvoiceService::syncOpeningBalanceSnapshots()
        // untuk mencocokkan invoice reguler ke baris OB terkait — sebelumnya tidak ter-index
        // sama sekali sehingga tiap panggilan (setiap update/recalculate invoice reguler,
        // bukan cuma saat import) adalah full table scan.
        Schema::table('tb_opening_balance_detail', function (Blueprint $table) {
            $table->index('no_invoice_asal', 'tb_opening_balance_detail_no_invoice_asal_index');
        });
    }

    public function down(): void
    {
        Schema::table('tb_opening_balance_detail', function (Blueprint $table) {
            $table->dropIndex('tb_opening_balance_detail_no_invoice_asal_index');
        });
    }
};
