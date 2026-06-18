<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_invoice', function (Blueprint $table) {
            // Akumulasi penyesuaian manual (write-off) yang mengurangi outstanding invoice.
            // Bukan turunan pembayaran — tidak di-reset saat recalculate.
            $table->decimal('total_penyesuaian', 15, 2)->default(0)->after('total_pembayaran');
        });
    }

    public function down(): void
    {
        Schema::table('tb_invoice', function (Blueprint $table) {
            $table->dropColumn('total_penyesuaian');
        });
    }
};
