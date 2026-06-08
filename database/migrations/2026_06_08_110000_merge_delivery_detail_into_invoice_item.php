<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_invoice_item', function (Blueprint $table) {
            $table->string('no_invoice_resto')->nullable()->after('keterangan');
            $table->string('kode_resto')->nullable()->after('no_invoice_resto');
            $table->string('nama_resto')->nullable()->after('kode_resto');
        });

        Schema::dropIfExists('tb_invoice_delivery_detail');
    }

    public function down(): void
    {
        Schema::table('tb_invoice_item', function (Blueprint $table) {
            $table->dropColumn(['no_invoice_resto', 'kode_resto', 'nama_resto']);
        });

        Schema::create('tb_invoice_delivery_detail', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->foreign('invoice_id')->references('id')->on('tb_invoice')->onDelete('cascade');
            $table->string('no_invoice_resto')->nullable();
            $table->string('kode_resto')->nullable();
            $table->string('nama_resto')->nullable();
            $table->string('kode_barang')->nullable();
            $table->string('nama_barang');
            $table->decimal('qty', 12, 2);
            $table->string('satuan')->nullable();
            $table->decimal('harga_satuan', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->timestamps();
        });
    }
};
