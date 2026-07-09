<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_pembayaran_ar', function (Blueprint $table) {
            $table->string('bukti_disk')->nullable()->after('bukti_uploaded_at');
            $table->string('bukti_path')->nullable()->after('bukti_disk');
        });
    }

    public function down(): void
    {
        Schema::table('tb_pembayaran_ar', function (Blueprint $table) {
            $table->dropColumn(['bukti_disk', 'bukti_path']);
        });
    }
};
