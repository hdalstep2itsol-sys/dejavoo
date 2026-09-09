<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DevelopmentUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Development users may only be seeded in the local environment.');
        }

        $password = (string) env('DEV_TEST_USER_PASSWORD');

        if (mb_strlen($password) < 12) {
            throw new RuntimeException('DEV_TEST_USER_PASSWORD must contain at least 12 characters.');
        }

        $users = [
            ['name' => 'Owner Admin', 'email' => 'owner.admin@example.test', 'role' => UserRole::OwnerAdmin],
            ['name' => 'Driver', 'email' => 'driver@example.test', 'role' => UserRole::Driver],
            ['name' => 'Warehouse Staff', 'email' => 'warehouse@example.test', 'role' => UserRole::WarehouseStaff],
        ];

        foreach ($users as $user) {
            User::query()->updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'role' => $user['role'],
                    'is_active' => true,
                    'email_verified_at' => now(),
                    'password' => Hash::make($password),
                ],
            );
        }
    }
}
