<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_perusahaan', function (Blueprint $table) {
            $table->id();
            $table->string('kode_perusahaan')->unique();
            $table->string('nama_singkatan_perusahaan');
            $table->boolean('status')->default(true);
            $table->text('keterangan')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_perusahaan');
    }
};
