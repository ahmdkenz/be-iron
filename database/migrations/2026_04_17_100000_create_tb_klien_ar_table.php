<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_klien_ar', function (Blueprint $table) {
            $table->id();
            $table->string('kode_klien', 20)->unique();
            $table->string('nama_klien');
            $table->string('alias')->nullable();
            $table->enum('tipe_klien', ['PT', 'RESTO', 'STOKIS'])->default('RESTO');
            $table->string('tipe_outlet')->nullable();
            $table->string('stokis_area')->nullable();
            $table->string('no_npwp', 30)->nullable();
            $table->string('kat_1')->nullable();
            $table->string('kat_2')->nullable();
            $table->foreignId('perusahaan_id')->constrained('tb_perusahaan')->restrictOnDelete();
            $table->foreignId('karyawan_ar_id')->constrained('tb_karyawan')->restrictOnDelete();
            $table->foreignId('resto_id')->nullable()->constrained('tb_resto')->nullOnDelete();
            $table->boolean('status')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_klien_ar');
    }
};
