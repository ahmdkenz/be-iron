<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_barang', function (Blueprint $table) {
            $table->id();
            $table->string('kode_barang');
            $table->string('nama_barang');
            $table->text('spesifikasi')->nullable();
            $table->foreignId('perusahaan_id')->constrained('tb_perusahaan')->restrictOnDelete();
            $table->foreignId('brand_id')->constrained('tb_brand')->restrictOnDelete();
            $table->text('keterangan')->nullable();
            $table->boolean('status')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_barang');
    }
};
