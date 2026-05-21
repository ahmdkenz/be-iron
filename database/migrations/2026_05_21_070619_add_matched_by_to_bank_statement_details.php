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
            if (!Schema::hasColumn('tb_bank_statement_detail', 'matched_by')) {
                $table->unsignedBigInteger('matched_by')->nullable()->after('pembayaran_ar_id');
            }
            $table->foreign('matched_by')->references('id')->on('tb_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_bank_statement_detail', function (Blueprint $table) {
            $table->dropForeign(['matched_by']);
            $table->dropColumn('matched_by');
        });
    }
};
