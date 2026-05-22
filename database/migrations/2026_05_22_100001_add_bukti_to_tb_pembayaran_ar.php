<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_pembayaran_ar', function (Blueprint $table) {
            $table->string('bukti_gdrive_file_id')->nullable()->after('keterangan');
            $table->string('bukti_gdrive_folder_id')->nullable()->after('bukti_gdrive_file_id');
            $table->string('bukti_file_name')->nullable()->after('bukti_gdrive_folder_id');
            $table->unsignedBigInteger('bukti_file_size')->nullable()->after('bukti_file_name');
            $table->string('bukti_mime_type')->nullable()->after('bukti_file_size');
            $table->timestamp('bukti_uploaded_at')->nullable()->after('bukti_mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('tb_pembayaran_ar', function (Blueprint $table) {
            $table->dropColumn([
                'bukti_gdrive_file_id',
                'bukti_gdrive_folder_id',
                'bukti_file_name',
                'bukti_file_size',
                'bukti_mime_type',
                'bukti_uploaded_at',
            ]);
        });
    }
};
