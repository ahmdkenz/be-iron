<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_bank_statement_detail', function (Blueprint $table) {
            $table->enum('status_posting_2', ['PENDING', 'POSTED'])->default('PENDING')->after('status_cocok');
            $table->timestamp('posted_at')->nullable()->after('status_posting_2');
            $table->foreignId('posted_by')->nullable()->after('posted_at')->constrained('tb_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_bank_statement_detail', function (Blueprint $table) {
            $table->dropForeign(['posted_by']);
            $table->dropColumn(['status_posting_2', 'posted_at', 'posted_by']);
        });
    }
};
