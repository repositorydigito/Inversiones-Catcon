<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(UserSeeder::class);
        $this->call(CompanySeeder::class);
        $this->call(MeasureUnitSeeder::class);
        $this->call(UbigeoSeeder::class);
        $this->call(OperationalExpenseConfigSeeder::class);
        $this->call(ServiceSeeder::class);
    }
}
