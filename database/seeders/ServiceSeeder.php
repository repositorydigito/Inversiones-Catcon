<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Service;

class ServiceSeeder extends Seeder
{    
    public function run(): void
    {
        $services = [
            ['code' => '00001', 'name' => 'TRASLADO DE SAL LAVADA UPGRADING HUACHO GRANEL'],
            ['code' => '00002', 'name' => 'TRASLADO DE MAIZ A GRANEL'],
            ['code' => '00003', 'name' => 'TRASLADO DE TORTA DE SOYA A GRANEL'],
            ['code' => '00004', 'name' => 'TRASLADO DE ROCA CALIZA'],
            ['code' => '00005', 'name' => 'TRASLADO DE FRIJOL DE SOYA A GRANEL'],
            ['code' => '00006', 'name' => 'TRASLADO DE SAL LAVADA ESPECIAL HUACHO GRANEL'],
            ['code' => '00007', 'name' => 'TRASLADO DE TORTA DE SOYA'],
        ];

        foreach ($services as $service) {
            Service::create($service);
        }
    }
}
