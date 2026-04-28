<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\Enums\RoleEnum;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'name'       => RoleEnum::ADMIN->value,
                'guard_name' => 'web',
                'nama_role'  => RoleEnum::ADMIN->label(),
                'keterangan' => 'Administrator sistem dengan akses konfigurasi dan master data',
                'status'     => true,
            ],
            [
                'name'       => RoleEnum::DIREKTUR->value,
                'guard_name' => 'web',
                'nama_role'  => RoleEnum::DIREKTUR->label(),
                'keterangan' => 'Approver final untuk opening balance dan monitoring eksekutif',
                'status'     => true,
            ],
            [
                'name'       => RoleEnum::MANAGER->value,
                'guard_name' => 'web',
                'nama_role'  => RoleEnum::MANAGER->label(),
                'keterangan' => 'Pengelola operasional finance dan account receivable',
                'status'     => true,
            ],
            [
                'name'       => RoleEnum::SUPERVISOR->value,
                'guard_name' => 'web',
                'nama_role'  => RoleEnum::SUPERVISOR->label(),
                'keterangan' => 'Supervisor operasional finance dan account receivable',
                'status'     => true,
            ],
            [
                'name'       => RoleEnum::AR->value,
                'guard_name' => 'web',
                'nama_role'  => RoleEnum::AR->label(),
                'keterangan' => 'Operator account receivable dan penagihan harian',
                'status'     => true,
            ],
            [
                'name'       => RoleEnum::AP->value,
                'guard_name' => 'web',
                'nama_role'  => RoleEnum::AP->label(),
                'keterangan' => 'Operator account payable',
                'status'     => true,
            ],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['name' => $role['name'], 'guard_name' => $role['guard_name']],
                $role
            );
        }
    }
}
