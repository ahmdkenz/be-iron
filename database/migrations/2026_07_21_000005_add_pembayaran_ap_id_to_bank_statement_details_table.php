<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tb_bank_statement_detail', function (Blueprint $table) {
            if (!Schema::hasColumn('tb_bank_statement_detail', 'pembayaran_ap_id')) {
                $table->foreignId('pembayaran_ap_id')
                    ->nullable()
                    ->after('pembayaran_ar_id')
                    ->constrained('tb_pembayaran_ap')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tb_bank_statement_detail', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pembayaran_ap_id');
        });
    }
};
