<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_ending_balance_koreksi', function (Blueprint $table) {
            // Tautan opsional ke invoice tertentu. Jika terisi, koreksi ini adalah
            // penyesuaian per-invoice yang mengurangi outstanding invoice tersebut.
            $table->unsignedBigInteger('invoice_id')->nullable()->after('klien_ar_id');

            $table->index('invoice_id');

            $table->foreign('invoice_id')
                ->references('id')->on('tb_invoice')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_ending_balance_koreksi', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
            $table->dropIndex(['invoice_id']);
            $table->dropColumn('invoice_id');
        });
    }
};
