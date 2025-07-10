<?php

namespace App\Filament\Resources\DespatchResource\Pages;

use App\Filament\Resources\DespatchResource;
use App\Services\DespatchService;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Exception;

class CreateDespatch extends CreateRecord
{
    protected static string $resource = DespatchResource::class;

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