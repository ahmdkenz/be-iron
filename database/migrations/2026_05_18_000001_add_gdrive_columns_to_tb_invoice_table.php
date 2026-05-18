<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_invoice', function (Blueprint $table) {
            $table->string('gdrive_file_id', 255)->nullable()->after('approved_token');
            $table->string('gdrive_folder_id', 255)->nullable()->after('gdrive_file_id');
        });
    }

    public function down(): void
    {
        Schema::table('tb_invoice', function (Blueprint $table) {
            $table->dropColumn(['gdrive_file_id', 'gdrive_folder_id']);
        });
    }
};
