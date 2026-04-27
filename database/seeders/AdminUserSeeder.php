<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@connector.local'],
            [
                'name'      => 'Admin',
                'password'  => Hash::make('Admin@1234!'),
                'role'      => User::ROLE_ADMIN,
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'manager@connector.local'],
            [
                'name'      => 'Manager',
                'password'  => Hash::make('Manager@1234!'),
                'role'      => User::ROLE_MANAGER,
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'viewer@connector.local'],
            [
                'name'      => 'Viewer',
                'password'  => Hash::make('Viewer@1234!'),
                'role'      => User::ROLE_VIEWER,
                'is_active' => true,
            ]
        );
    }
}
