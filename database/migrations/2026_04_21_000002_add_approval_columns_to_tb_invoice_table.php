<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_invoice', function (Blueprint $table) {
            $table->string('approval_status', 20)->nullable()->after('status');
            $table->dateTime('submitted_at')->nullable()->after('approval_status');
            $table->foreignId('submitted_by')->nullable()->after('submitted_at')->constrained('tb_users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable()->after('submitted_by');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('tb_users')->nullOnDelete();
            $table->dateTime('rejected_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->after('rejected_at')->constrained('tb_users')->nullOnDelete();

            $table->index(['is_opening_balance', 'approval_status'], 'tb_invoice_opening_balance_approval_idx');
        });

        DB::table('tb_invoice')
            ->where('is_opening_balance', true)
            ->update([
                'approval_status' => 'APPROVED',
                'submitted_at'    => DB::raw('created_at'),
                'approved_at'     => DB::raw('created_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('tb_invoice', function (Blueprint $table) {
            $table->dropIndex('tb_invoice_opening_balance_approval_idx');
            $table->dropForeign(['submitted_by']);
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['rejected_by']);
            $table->dropColumn([
                'approval_status',
                'submitted_at',
                'submitted_by',
                'approved_at',
                'approved_by',
                'rejected_at',
                'rejected_by',
            ]);
        });
    }
};
