<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_invoice', function (Blueprint $table) {
            $table->foreignId('resto_id')
                  ->nullable()
                  ->after('klien_ar_id')
                  ->constrained('tb_resto')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_invoice', function (Blueprint $table) {
            $table->dropForeign(['resto_id']);
            $table->dropColumn('resto_id');
        });
    }
};
