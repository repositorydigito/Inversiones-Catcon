<?php

namespace App\Filament\Resources\DespatchResource\Pages;

use App\Filament\Resources\DespatchResource;
use App\Services\DespatchService;
use App\Models\Driver;
use App\Models\Vehicle;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Exception;

class CreateDespatch extends CreateRecord
{
    protected static string $resource = DespatchResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
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

                // Lanzar excepción para detener el guardado
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

                // Lanzar excepción para detener el guardado
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
                continue; // Saltar el vehículo principal
            }

            // VALIDACIÓN 2: Verificar que el vehículo exista y tenga conductor
            $vehicle = Vehicle::with('driver')->find($vehicleId);
            if (!$vehicle) {
                $errorMessages[] = "Vehículo ID {$vehicleId}: No encontrado";
                continue;
            }

            if (!$vehicle->driver) {
                $errorMessages[] = "🚛 {$vehicle->plate_number}: Sin conductor asignado";
                continue; // Saltar vehículos sin conductor
            }

            // VALIDACIÓN 3: No puede tener el mismo conductor que el principal
            if (isset($allData['driver_id']) && $vehicle->driver->id == $allData['driver_id']) {
                $errorMessages[] = "🚛 {$vehicle->plate_number}: Su conductor ya es el conductor principal";
                continue; // Saltar si el conductor del vehículo es el mismo que el principal
            }

            // VALIDACIÓN 4: No duplicar vehículos en secundarios
            if (in_array($vehicleId, $validated)) {
                continue; // Saltar duplicados
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

        // Mostrar todos los errores de validación si existen
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

    protected function afterCreate(): void
    {
        // Automáticamente enviar la guía a Nubefact después de crearla
        $this->sendToNubefact();
    }

    protected function sendToNubefact(): void
    {
        try {
            $despatchService = app(DespatchService::class);
            $response = $despatchService->generateDespatch($this->record);

            Notification::make()
                ->title('Guía Creada y Enviada Exitosamente')
                ->body("La guía #{$this->record->series}-{$this->record->number} ha sido creada y enviada a SUNAT. Estado: " . ($this->record->accepted_by_sunat ? 'ACEPTADA' : 'PROCESANDO'))
                ->success()
                ->send();

        } catch (Exception $e) {
            Notification::make()
                ->title('Guía Creada - Error al Enviar a SUNAT')
                ->body("La guía fue creada correctamente, pero hubo un error al enviarla a SUNAT: " . $e->getMessage())
                ->warning()
                ->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        // Redirige al listado después de crear para que el usuario pueda ver el estado
        return $this->getResource()::getUrl('index');
    }
}
