<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_import_master_batch', function (Blueprint $table) {
            $table->unsignedInteger('invoice_total')->default(0)->after('barang_failed');
            $table->unsignedInteger('invoice_processed')->default(0)->after('invoice_total');
            $table->unsignedInteger('invoice_inserted')->default(0)->after('invoice_processed');
            $table->unsignedInteger('invoice_updated')->default(0)->after('invoice_inserted');
            $table->unsignedInteger('invoice_skipped')->default(0)->after('invoice_updated');
            $table->unsignedInteger('invoice_failed')->default(0)->after('invoice_skipped');
        });
    }

    public function down(): void
    {
        Schema::table('tb_import_master_batch', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_total',
                'invoice_processed',
                'invoice_inserted',
                'invoice_updated',
                'invoice_skipped',
                'invoice_failed',
            ]);
        });
    }
};
