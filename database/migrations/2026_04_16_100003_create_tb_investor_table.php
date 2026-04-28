<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_investor', function (Blueprint $table) {
            $table->id();
            $table->string('nama_investor');
            $table->string('ktp', 20)->unique()->nullable();
            $table->string('npwp', 20)->unique()->nullable();
            $table->string('no_hp', 20)->nullable();
            $table->string('pengelola')->nullable();
            $table->string('no_hp_pengelola', 20)->nullable();
            $table->text('alamat')->nullable();
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
        Schema::dropIfExists('tb_investor');
    }
};
