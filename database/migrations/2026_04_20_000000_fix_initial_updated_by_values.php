<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'tb_users',
            'tb_role',
            'tb_perusahaan',
            'tb_karyawan',
            'tb_brand',
            'tb_investor',
            'tb_resto',
            'tb_barang',
            'tb_klien_ar',
            'tb_invoice',
            'tb_pembayaran_ar',
        ];

        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)
                ->whereNotNull('created_by')
                ->whereNotNull('updated_by')
                ->whereColumn('created_by', 'updated_by')
                ->whereColumn('created_at', 'updated_at')
                ->update(['updated_by' => null]);
        }
    }

    public function down(): void
    {
        // Data cleanup cannot be restored safely.
    }
};
