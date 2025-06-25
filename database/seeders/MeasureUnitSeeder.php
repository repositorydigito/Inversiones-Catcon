<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\MeasureUnit;

class MeasureUnitSeeder extends Seeder
{    
    public function run(): void
    {
        $units = [
            ['code' => 'NIU', 'description' => 'Unidades'],
            ['code' => 'ZZ', 'description' => 'Servicio'],
            ['code' => 'KGM', 'description' => 'Kilos'],
            ['code' => 'GRM', 'description' => 'Gramos'],
            ['code' => 'TNE', 'description' => 'Toneladas'],
        ];

        foreach ($units as $unit) {
            MeasureUnit::create($unit);
        }
    }
}
