<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_invoice', function (Blueprint $table) {
            $table->uuid('prepared_token')->nullable()->unique()->after('keterangan');
            $table->uuid('approved_token')->nullable()->unique()->after('prepared_token');
        });
    }

    public function down(): void
    {
        Schema::table('tb_invoice', function (Blueprint $table) {
            $table->dropColumn(['prepared_token', 'approved_token']);
        });
    }
};
