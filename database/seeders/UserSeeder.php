<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'name' => 'Administrador Catcon',
            'email' => 'admin@catcon.pe',
            'password' => bcrypt('admin'),
            'email_verified_at' => now(),
        ]);
    }
}
