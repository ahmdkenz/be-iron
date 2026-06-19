<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menghapus role DIREKTUR dari sistem.
 *
 * Approval Opening Balance dipindahkan ke role MANAGER, sehingga role DIREKTUR
 * tidak lagi dipakai. Semua user yang masih ber-role DIREKTUR di-reassign ke
 * MANAGER agar tidak ada akun yang kehilangan akses, lalu record role beserta
 * relasi permission-nya dibersihkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $direktur = DB::table('tb_role')
            ->where('name', 'DIREKTUR')
            ->where('guard_name', 'web')
            ->first();

        if (!$direktur) {
            return;
        }

        $manager = DB::table('tb_role')
            ->where('name', 'MANAGER')
            ->where('guard_name', 'web')
            ->first();

        // Reassign user DIREKTUR -> MANAGER (jika MANAGER ada).
        if ($manager) {
            $assignments = DB::table('model_has_roles')
                ->where('role_id', $direktur->id)
                ->get();

            foreach ($assignments as $assignment) {
                $alreadyManager = DB::table('model_has_roles')
                    ->where('role_id', $manager->id)
                    ->where('model_type', $assignment->model_type)
                    ->where('model_id', $assignment->model_id)
                    ->exists();

                if ($alreadyManager) {
                    // Hindari duplikasi primary key komposit: cukup hapus baris DIREKTUR.
                    DB::table('model_has_roles')
                        ->where('role_id', $direktur->id)
                        ->where('model_type', $assignment->model_type)
                        ->where('model_id', $assignment->model_id)
                        ->delete();

                    continue;
                }

                DB::table('model_has_roles')
                    ->where('role_id', $direktur->id)
                    ->where('model_type', $assignment->model_type)
                    ->where('model_id', $assignment->model_id)
                    ->update(['role_id' => $manager->id]);
            }
        } else {
            // Tidak ada MANAGER: lepas assignment DIREKTUR agar tidak menggantung.
            DB::table('model_has_roles')->where('role_id', $direktur->id)->delete();
        }

        // Bersihkan permission & record role DIREKTUR.
        DB::table('role_has_permissions')->where('role_id', $direktur->id)->delete();
        DB::table('tb_role')->where('id', $direktur->id)->delete();
    }

    public function down(): void
    {
        // Best-effort: buat ulang record role DIREKTUR (tanpa mengembalikan assignment user).
        $exists = DB::table('tb_role')
            ->where('name', 'DIREKTUR')
            ->where('guard_name', 'web')
            ->exists();

        if (!$exists) {
            $now = now();

            DB::table('tb_role')->insert([
                'name'       => 'DIREKTUR',
                'guard_name' => 'web',
                'nama_role'  => 'Direktur',
                'keterangan' => 'Approver final untuk opening balance dan monitoring eksekutif',
                'status'     => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
