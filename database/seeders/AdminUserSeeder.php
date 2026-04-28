<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['username' => 'admin'],
            [
                'email'    => 'admin@iron.local',
                'password' => bcrypt('admin'),
                'status'   => true,
            ]
        );

        $admin->syncRoles(['ADMIN']);
    }
}
