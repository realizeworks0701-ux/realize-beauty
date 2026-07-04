<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $salon = Salon::create([
            'name' => 'Realize Beauty',
            'phone' => '03-1234-5678',
            'postal_code' => '150-0001',
            'address' => '東京都渋谷区神宮前1-1-1',
            'is_active' => true,
        ]);

        User::create([
            'salon_id' => $salon->id,
            'name' => '山田 太郎',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => Role::Owner,
            'is_active' => true,
        ]);
    }
}
