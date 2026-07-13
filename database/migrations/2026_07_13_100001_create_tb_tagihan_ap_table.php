<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_tagihan_ap', function (Blueprint $table) {
            $table->id();
            $table->string('no_tagihan', 50)->unique();
            $table->string('no_invoice_vendor')->nullable();
            $table->date('tanggal_tagihan');
            $table->date('tanggal_jatuh_tempo')->nullable();
            $table->foreignId('vendor_ap_id')->constrained('tb_vendor_ap')->restrictOnDelete();
            $table->foreignId('perusahaan_id')->constrained('tb_perusahaan')->restrictOnDelete();
            $table->foreignId('karyawan_id')->constrained('tb_karyawan')->restrictOnDelete();
            $table->string('no_po')->nullable();
            $table->string('no_terima_barang')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('ppn_masukan', 15, 2)->default(0);
            $table->decimal('pph23', 15, 2)->default(0);
            $table->decimal('total_tagihan', 15, 2)->default(0);
            $table->decimal('total_pembayaran', 15, 2)->default(0);
            $table->decimal('total_penyesuaian', 15, 2)->default(0);
            $table->decimal('sisa_tagihan', 15, 2)->default(0);
            $table->enum('status', ['DRAFT', 'DITERIMA', 'SEBAGIAN', 'LUNAS'])->default('DRAFT');
            $table->string('approval_status', 20)->nullable();
            $table->boolean('is_opening_balance')->default(false);
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('tb_users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('tb_users')->nullOnDelete();
            $table->dateTime('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('tb_users')->nullOnDelete();
            $table->text('keterangan')->nullable();
            $table->string('prepared_token')->nullable();
            $table->string('approved_token')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vendor_ap_id', 'status'], 'tb_tagihan_ap_vendor_status_idx');
            $table->index(['is_opening_balance', 'approval_status'], 'tb_tagihan_ap_opening_balance_approval_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_tagihan_ap');
    }
};
