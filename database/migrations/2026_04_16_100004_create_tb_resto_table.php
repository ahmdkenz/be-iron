<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_resto', function (Blueprint $table) {
            $table->id();
            $table->string('kode_resto');
            $table->string('nama_resto');
            $table->foreignId('investor_id')->nullable()->constrained('tb_investor')->nullOnDelete();
            $table->foreignId('perusahaan_id')->constrained('tb_perusahaan')->restrictOnDelete();
            $table->foreignId('brand_id')->constrained('tb_brand')->restrictOnDelete();
            $table->foreignId('karyawan_id')->nullable()->constrained('tb_karyawan')->nullOnDelete();
            $table->string('area')->nullable();
            $table->string('kota')->nullable();
            $table->text('alamat')->nullable();
            $table->string('no_telp', 20)->nullable();
            $table->date('tgl_aktif')->nullable();
            $table->boolean('status')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_resto');
    }
};
