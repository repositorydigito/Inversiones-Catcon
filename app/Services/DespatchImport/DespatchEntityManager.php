<?php

namespace App\Services\DespatchImport;

use App\Models\Client;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Models\Company;
use Exception;

class DespatchEntityManager
{
    /**
     * Procesa todas las entidades relacionadas
     */
    public function processRelatedEntities(array $xmlData): array
    {
        $entities = ['created_entities' => []];

        // 1. Validar transportista (debe ser tu empresa)
        $company = Company::where('ruc', $xmlData['transportista']['ruc'])->first();
        if (!$company) {
            throw new Exception("El transportista {$xmlData['transportista']['ruc']} no corresponde a tu empresa");
        }
        $entities['company'] = $company;

        // 2. Procesar remitente
        $entities['remitente'] = $this->findOrCreateClient($xmlData['remitente']);
        if ($entities['remitente']->wasRecentlyCreated) {
            $entities['created_entities'][] = "Cliente remitente: {$entities['remitente']->name}";
        }

        // 3. Procesar destinatario
        $entities['destinatario'] = $this->findOrCreateClient($xmlData['destinatario']);
        if ($entities['destinatario']->wasRecentlyCreated) {
            $entities['created_entities'][] = "Cliente destinatario: {$entities['destinatario']->name}";
        }

        // 4. Procesar conductor
        $entities['conductor'] = $this->findOrCreateDriver($xmlData['conductor']);
        if ($entities['conductor']->wasRecentlyCreated) {
            $entities['created_entities'][] = "Conductor: {$entities['conductor']->first_name} {$entities['conductor']->last_name}";
        }

        // 5. Procesar vehículo principal
        $entities['vehiculo'] = $this->findOrCreateVehicle($xmlData['vehiculo'], $entities['conductor']);
        if ($entities['vehiculo']->wasRecentlyCreated) {
            $entities['created_entities'][] = "Vehículo principal: {$entities['vehiculo']->plate_number}";
        }

        // 6. Procesar vehículos secundarios
        $entities['vehiculos_secundarios'] = [];
        foreach ($xmlData['vehiculos_secundarios'] as $vehiculoData) {
            // Pasar el conductor principal también a los secundarios
            $vehiculo = $this->findOrCreateVehicle($vehiculoData, $entities['conductor']);
            $entities['vehiculos_secundarios'][] = $vehiculo;

            if ($vehiculo->wasRecentlyCreated) {
                $entities['created_entities'][] = "Vehículo secundario: {$vehiculo->plate_number}";
            }
        }

        return $entities;
    }
    /**
     * Busca o crea un cliente
     */
    protected function findOrCreateClient(array $clientData): Client
    {
        return Client::firstOrCreate(
            ['document_number' => $clientData['ruc']],
            [
                'name' => $clientData['nombre'],
                'document_type' => 'RUC'
            ]
        );
    }
    /**
     * Busca o crea un conductor
     */
    protected function findOrCreateDriver(array $driverData): Driver
    {
        // Buscar por documento primero (insensible a mayúsculas)
        $existingDriver = Driver::where('document_number', $driverData['document_number'])->first();

        if ($existingDriver) {
            // Si existe, actualizar nombres si están mejor formateados
            $existingDriver->update([
                'first_name' => $driverData['first_name'],
                'last_name' => $driverData['last_name'],
                'license_number' => $driverData['license_number']
            ]);

            return $existingDriver;
        }

        // Si no existe, crear nuevo
        return Driver::create([
            'first_name' => $driverData['first_name'],
            'last_name' => $driverData['last_name'],
            'document_type' => $driverData['document_type'],
            'document_number' => $driverData['document_number'],
            'license_number' => $driverData['license_number']
        ]);
    }
    /**
     * Busca o crea un vehículo
     */
    protected function findOrCreateVehicle(array $vehicleData, Driver $driver = null): Vehicle
    {
        $vehicle = Vehicle::firstOrCreate(
            ['plate_number' => $vehicleData['plate_number']],
            [
                'brand' => 'Por Editar',
                'model' => 'Por Editar',
                'vehicle_certificate' => $vehicleData['vehicle_certificate'] ?? null,
                'driver_id' => $driver?->id
            ]
        );

        // Si existe y no tiene conductor asignado, asignar el conductor
        if (!$vehicle->wasRecentlyCreated && !$vehicle->driver_id && $driver) {
            $vehicle->update(['driver_id' => $driver->id]);
        }

        return $vehicle;
    }
}
