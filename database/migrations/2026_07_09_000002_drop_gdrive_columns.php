<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_invoice', function (Blueprint $table) {
            $table->dropColumn(['gdrive_file_id', 'gdrive_folder_id']);
        });

        Schema::table('tb_pembayaran_ar', function (Blueprint $table) {
            $table->dropColumn(['bukti_gdrive_file_id', 'bukti_gdrive_folder_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tb_invoice', function (Blueprint $table) {
            $table->string('gdrive_file_id')->nullable()->after('approved_token');
            $table->string('gdrive_folder_id')->nullable()->after('gdrive_file_id');
        });

        Schema::table('tb_pembayaran_ar', function (Blueprint $table) {
            $table->string('bukti_gdrive_file_id')->nullable()->after('keterangan');
            $table->string('bukti_gdrive_folder_id')->nullable()->after('bukti_gdrive_file_id');
        });
    }
};
