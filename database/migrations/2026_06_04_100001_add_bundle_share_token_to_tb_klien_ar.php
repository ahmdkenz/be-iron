<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_klien_ar', function (Blueprint $table) {
            $table->uuid('bundle_share_token')
                  ->nullable()
                  ->unique()
                  ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tb_klien_ar', function (Blueprint $table) {
            $table->dropColumn('bundle_share_token');
        });
    }
};