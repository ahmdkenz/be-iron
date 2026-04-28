<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('tb_role')
            ->where('name', 'ADMIN')
            ->where('guard_name', 'web')
            ->update([
                'nama_role'  => 'Admin',
                'keterangan' => 'Administrator sistem dengan akses konfigurasi dan master data',
                'status'     => true,
                'updated_at' => $now,
            ]);

        if (
            DB::table('tb_role')->where('name', 'FINANCE')->where('guard_name', 'web')->exists()
            && !DB::table('tb_role')->where('name', 'MANAGER')->where('guard_name', 'web')->exists()
        ) {
            DB::table('tb_role')
                ->where('name', 'FINANCE')
                ->where('guard_name', 'web')
                ->update([
                    'name'       => 'MANAGER',
                    'nama_role'  => 'Manager',
                    'keterangan' => 'Pengelola operasional finance dan account receivable',
                    'status'     => true,
                    'updated_at' => $now,
                ]);
        }

        if (
            DB::table('tb_role')->where('name', 'PIC_AR')->where('guard_name', 'web')->exists()
            && !DB::table('tb_role')->where('name', 'AR')->where('guard_name', 'web')->exists()
        ) {
            DB::table('tb_role')
                ->where('name', 'PIC_AR')
                ->where('guard_name', 'web')
                ->update([
                    'name'       => 'AR',
                    'nama_role'  => 'AR',
                    'keterangan' => 'Operator account receivable dan penagihan harian',
                    'status'     => true,
                    'updated_at' => $now,
                ]);
        }

        $roles = [
            [
                'name'       => 'DIREKTUR',
                'guard_name' => 'web',
                'nama_role'  => 'Direktur',
                'keterangan' => 'Approver final untuk opening balance dan monitoring eksekutif',
                'status'     => true,
            ],
            [
                'name'       => 'MANAGER',
                'guard_name' => 'web',
                'nama_role'  => 'Manager',
                'keterangan' => 'Pengelola operasional finance dan account receivable',
                'status'     => true,
            ],
            [
                'name'       => 'SUPERVISOR',
                'guard_name' => 'web',
                'nama_role'  => 'Supervisor',
                'keterangan' => 'Supervisor operasional finance dan account receivable',
                'status'     => true,
            ],
            [
                'name'       => 'AR',
                'guard_name' => 'web',
                'nama_role'  => 'AR',
                'keterangan' => 'Operator account receivable dan penagihan harian',
                'status'     => true,
            ],
            [
                'name'       => 'AP',
                'guard_name' => 'web',
                'nama_role'  => 'AP',
                'keterangan' => 'Operator account payable',
                'status'     => true,
            ],
        ];

        foreach ($roles as $role) {
            $exists = DB::table('tb_role')
                ->where('name', $role['name'])
                ->where('guard_name', $role['guard_name'])
                ->exists();

            if ($exists) {
                DB::table('tb_role')
                    ->where('name', $role['name'])
                    ->where('guard_name', $role['guard_name'])
                    ->update([
                        'nama_role'  => $role['nama_role'],
                        'keterangan' => $role['keterangan'],
                        'status'     => $role['status'],
                        'updated_at' => $now,
                    ]);

                continue;
            }

            DB::table('tb_role')->insert([
                ...$role,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $now = now();

        DB::table('tb_role')
            ->where('name', 'ADMIN')
            ->where('guard_name', 'web')
            ->update([
                'nama_role'  => 'Administrator',
                'keterangan' => 'Super Admin dengan akses penuh ke seluruh sistem',
                'updated_at' => $now,
            ]);

        if (
            DB::table('tb_role')->where('name', 'MANAGER')->where('guard_name', 'web')->exists()
            && !DB::table('tb_role')->where('name', 'FINANCE')->where('guard_name', 'web')->exists()
        ) {
            DB::table('tb_role')
                ->where('name', 'MANAGER')
                ->where('guard_name', 'web')
                ->update([
                    'name'       => 'FINANCE',
                    'nama_role'  => 'Finance',
                    'keterangan' => 'Staff keuangan dengan akses laporan dan transaksi',
                    'updated_at' => $now,
                ]);
        }

        if (
            DB::table('tb_role')->where('name', 'AR')->where('guard_name', 'web')->exists()
            && !DB::table('tb_role')->where('name', 'PIC_AR')->where('guard_name', 'web')->exists()
        ) {
            DB::table('tb_role')
                ->where('name', 'AR')
                ->where('guard_name', 'web')
                ->update([
                    'name'       => 'PIC_AR',
                    'nama_role'  => 'PIC Accounts Receivable',
                    'keterangan' => 'Person In Charge untuk piutang resto miliknya',
                    'updated_at' => $now,
                ]);
        }

        DB::table('tb_role')
            ->whereIn('name', ['DIREKTUR', 'SUPERVISOR', 'AP'])
            ->where('guard_name', 'web')
            ->delete();
    }
};
