<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@followplus.com'],
            [
                'password_hash' => Hash::make('admin123456', ['memory' => 65536, 'time' => 4, 'threads' => 3]),
                'invite_code' => 'ADMIN' . Str::random(4),
                'ref_path' => '/',
                'ref_depth' => 0,
                'role' => 'admin',
                'status' => 'active',
                'first_joined_at' => now(),
            ]
        );

        // Create admin profile if not exists
        $admin->profile()->firstOrCreate(
            ['user_id' => $admin->id],
            ['name' => 'System Administrator']
        );

        // Create admin KYC if not exists
        $admin->kyc()->firstOrCreate(
            ['user_id' => $admin->id],
            [
                'level' => 'advanced',
                'status' => 'approved',
            ]
        );

        $this->command->info('Admin user created: admin@followplus.com / admin123456');
    }
}
