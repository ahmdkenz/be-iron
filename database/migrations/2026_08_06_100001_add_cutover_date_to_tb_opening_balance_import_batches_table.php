<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_opening_balance_import_batches', function (Blueprint $table) {
            $table->date('cutover_date')->nullable()->after('is_csv');
        });
    }

    public function down(): void
    {
        Schema::table('tb_opening_balance_import_batches', function (Blueprint $table) {
            $table->dropColumn('cutover_date');
        });
    }
};
