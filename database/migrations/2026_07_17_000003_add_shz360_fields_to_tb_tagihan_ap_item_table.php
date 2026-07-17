<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_tagihan_ap_item', function (Blueprint $table) {
            $table->decimal('qty_po', 15, 4)->nullable()->after('qty');
            $table->decimal('ppn', 5, 2)->nullable()->after('harga_satuan');
            $table->string('status_detail_terima_po', 30)->nullable()->after('ppn');
            $table->decimal('qty_tolak', 15, 4)->nullable()->after('status_detail_terima_po');
            $table->text('keterangan_tolak')->nullable()->after('qty_tolak');
        });
    }

    public function down(): void
    {
        Schema::table('tb_tagihan_ap_item', function (Blueprint $table) {
            $table->dropColumn(['qty_po', 'ppn', 'status_detail_terima_po', 'qty_tolak', 'keterangan_tolak']);
        });
    }
};
