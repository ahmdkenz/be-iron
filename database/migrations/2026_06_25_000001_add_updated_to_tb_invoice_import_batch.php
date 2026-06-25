<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_invoice_import_batch', function (Blueprint $table) {
            $table->unsignedInteger('updated')->default(0)->after('inserted');
        });
    }

    public function down(): void
    {
        Schema::table('tb_invoice_import_batch', function (Blueprint $table) {
            $table->dropColumn('updated');
        });
    }
};
