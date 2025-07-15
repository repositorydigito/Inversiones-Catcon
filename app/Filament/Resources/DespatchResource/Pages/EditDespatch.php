<?php

namespace App\Filament\Resources\DespatchResource\Pages;

use App\Filament\Resources\DespatchResource;
use Filament\Actions;
use App\Models\Driver;
use App\Models\Vehicle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditDespatch extends EditRecord
{
    protected static string $resource = DespatchResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // VALIDAR TRANSPORTE PRINCIPAL
        $data = $this->validateMainTransport($data);

        // VALIDAR TRANSPORTE SECUNDARIO
        if (isset($data['secondaryVehicles'])) {
            $data['secondaryVehicles'] = $this->validateSecondaryVehicles($data['secondaryVehicles'], $data);
        }

        return $data;
    }
    private function validateMainTransport(array $data): array
    {
        // Validar que el vehículo principal tenga conductor
        if (isset($data['vehicle_id']) && $data['vehicle_id']) {
            $vehicle = Vehicle::with('driver')->find($data['vehicle_id']);

            if (!$vehicle) {
                Notification::make()
                    ->title('Vehículo no encontrado')
                    ->body('El vehículo seleccionado no existe')
                    ->danger()
                    ->persistent()
                    ->send();

                $data['vehicle_id'] = null;
                $data['driver_id'] = null;

                throw new \Exception('Vehículo principal no válido');
            }

            if (!$vehicle->driver) {
                Notification::make()
                    ->title('Vehículo sin conductor')
                    ->body("El vehículo {$vehicle->plate_number} no tiene conductor asignado. Asigna un conductor en el módulo de Vehículos antes de continuar.")
                    ->danger()
                    ->persistent()
                    ->send();

                $data['vehicle_id'] = null;
                $data['driver_id'] = null;

                throw new \Exception('Vehículo principal sin conductor asignado');
            }

            // Asegurar que el conductor se asigne correctamente
            $data['driver_id'] = $vehicle->driver->id;
        }

        return $data;
    }
    private function validateSecondaryVehicles(array $secondaryVehicleIds, array $allData): array
    {
        $validated = [];
        $errorMessages = [];

        foreach ($secondaryVehicleIds as $vehicleId) {
            // VALIDACIÓN 1: No puede ser el mismo vehículo principal
            if (isset($allData['vehicle_id']) && $vehicleId == $allData['vehicle_id']) {
                $vehicle = Vehicle::find($vehicleId);
                $errorMessages[] = "🚛 {$vehicle?->plate_number}: No puede ser vehículo principal y secundario a la vez";
                continue;
            }

            // VALIDACIÓN 2: Verificar que el vehículo exista y tenga conductor
            $vehicle = Vehicle::with('driver')->find($vehicleId);
            if (!$vehicle) {
                $errorMessages[] = "Vehículo ID {$vehicleId}: No encontrado";
                continue;
            }

            if (!$vehicle->driver) {
                $errorMessages[] = "🚛 {$vehicle->plate_number}: Sin conductor asignado";
                continue;
            }

            // VALIDACIÓN 3: No puede tener el mismo conductor que el principal
            if (isset($allData['driver_id']) && $vehicle->driver->id == $allData['driver_id']) {
                $errorMessages[] = "🚛 {$vehicle->plate_number}: Su conductor ya es el conductor principal";
                continue;
            }

            // VALIDACIÓN 4: No duplicar vehículos en secundarios
            if (in_array($vehicleId, $validated)) {
                continue;
            }

            $validated[] = $vehicleId;

            // VALIDACIÓN 5: Respetar límite SUNAT (máximo 2)
            if (count($validated) >= 2) {
                if (count($secondaryVehicleIds) > 2) {
                    $errorMessages[] = "Límite SUNAT: Solo se pueden seleccionar 2 vehículos secundarios máximo";
                }
                break;
            }
        }

        if (!empty($errorMessages)) {
            Notification::make()
                ->title('Problemas en Transporte Secundario')
                ->body('Se corrigieron los siguientes problemas:<br>• ' . implode('<br>• ', $errorMessages))
                ->warning()
                ->persistent()
                ->send();
        }

        return $validated;
    }

    protected function getRedirectUrl(): string
    {
        return DespatchResource::getUrl('index');
    }
    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
