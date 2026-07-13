<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_vendor_ap', function (Blueprint $table) {
            $table->id();
            $table->string('kode_vendor', 20)->unique();
            $table->string('nama_vendor');
            $table->string('no_npwp', 30)->nullable();
            $table->boolean('status_pkp')->default(false);
            $table->string('kategori')->nullable();
            $table->unsignedInteger('termin_hari')->nullable();
            $table->string('bank_nama')->nullable();
            $table->string('bank_no_rekening', 50)->nullable();
            $table->string('bank_atas_nama')->nullable();
            $table->foreignId('perusahaan_id')->constrained('tb_perusahaan')->restrictOnDelete();
            $table->foreignId('karyawan_ap_id')->constrained('tb_karyawan')->restrictOnDelete();
            $table->boolean('status')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_vendor_ap');
    }
};
