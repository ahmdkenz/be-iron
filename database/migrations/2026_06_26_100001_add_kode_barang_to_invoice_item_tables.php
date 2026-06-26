<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_invoice_item', function (Blueprint $table) {
            $table->string('kode_barang', 50)->nullable()->after('barang_id');
        });

        Schema::table('tb_opening_balance_detail_item', function (Blueprint $table) {
            $table->string('kode_barang', 50)->nullable()->after('barang_id');
        });
    }

    public function down(): void
    {
        Schema::table('tb_invoice_item', function (Blueprint $table) {
            $table->dropColumn('kode_barang');
        });

        Schema::table('tb_opening_balance_detail_item', function (Blueprint $table) {
            $table->dropColumn('kode_barang');
        });
    }
};
