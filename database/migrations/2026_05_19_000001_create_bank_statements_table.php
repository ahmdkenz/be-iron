<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_bank_statement', function (Blueprint $table) {
            $table->id();
            $table->enum('bank_type', ['BCA', 'MANDIRI', 'BNI', 'BRI']);
            $table->string('nama_file');
            $table->date('periode_awal')->nullable();
            $table->date('periode_akhir')->nullable();
            $table->integer('total_transaksi')->default(0);
            $table->decimal('total_kredit', 15, 2)->default(0);
            $table->integer('jumlah_matched')->default(0);
            $table->integer('jumlah_unmatched')->default(0);
            $table->unsignedBigInteger('uploaded_by');
            $table->foreign('uploaded_by')->references('id')->on('tb_users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_bank_statement');
    }
};
