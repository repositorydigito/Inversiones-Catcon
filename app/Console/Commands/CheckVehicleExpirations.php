<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Vehicle;
use App\Models\User;
use App\Notifications\SoatExpirationNotification; 
use App\Notifications\TechnicalReviewExpirationNotification;
use App\Notifications\TuceExpirationNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Notifications\DatabaseNotification; 
use Filament\Notifications\Notification as FilamentNotification;

class CheckVehicleExpirations extends Command
{
    protected $signature = 'app:check-vehicle-expirations';
    protected $description = 'Verifica las fechas de vencimiento de SOAT, Revisión Técnica y TUCE de vehículos y envía notificaciones.';

    public function handle(): void
    {
        Log::info('Comando app:check-vehicle-expirations iniciado.');
        $this->info('Iniciando verificación de vencimientos de vehículos...');

        $soatThresholdDays = 7;
        $technicalReviewThresholdDays = 14;
        $tuceThresholdDays = 30;

        $adminUser = User::first();

        if (!$adminUser) {
            Log::warning('No se encontró ningún usuario para enviar notificaciones de vencimiento de vehículos.');
            $this->error('No se encontró ningún usuario para enviar notificaciones. Abortando.');
            return;
        }

        $vehicles = Vehicle::all();

        foreach ($vehicles as $vehicle) {
            $today = Carbon::today();

            // --- Vencimiento SOAT ---
            if ($vehicle->soat_expiration_date instanceof Carbon) {
                $daysRemaining = $today->diffInDays($vehicle->soat_expiration_date, false);

                if ($daysRemaining <= $soatThresholdDays && $daysRemaining >= 0) {
                    $this->notifyFilamentIfNecessary(
                        $adminUser,
                        $vehicle,
                        'soat_expiration',
                        $soatThresholdDays,
                        [
                            'title' => '¡Vencimiento de SOAT!',
                            'body' => "El SOAT del vehículo con placa {$vehicle->plate_number} (Marca: {$vehicle->brand}, Modelo: {$vehicle->model}) vence en {$daysRemaining} día(s).",
                            'icon' => 'heroicon-o-calendar-days',
                            'color' => 'danger',
                            'url' => route('filament.admin.resources.vehicles.edit', $vehicle->id),
                            'type' => 'soat_expiration',
                            'vehicle_id' => $vehicle->id,
                            'expiration_date' => $vehicle->soat_expiration_date->format('Y-m-d'),
                        ]
                    );
                }
            }            

            // --- Vencimiento Revisión Técnica ---
            if ($vehicle->technical_review_expiration_date instanceof Carbon) {
                $daysRemaining = $today->diffInDays($vehicle->technical_review_expiration_date, false);

                if ($daysRemaining <= $technicalReviewThresholdDays && $daysRemaining >= 0) {
                    $this->notifyFilamentIfNecessary(
                        $adminUser,
                        $vehicle,
                        'technical_review_expiration',
                        $technicalReviewThresholdDays,
                        [
                            'title' => '¡Vencimiento de Revisión Técnica!',
                            'body' => "La Revisión Técnica del vehículo con placa {$vehicle->plate_number} (Marca: {$vehicle->brand}, Modelo: {$vehicle->model}) vence en {$daysRemaining} día(s).",
                            'icon' => 'heroicon-o-wrench-screwdriver',
                            'color' => 'warning',
                            'url' => route('filament.admin.resources.vehicles.edit', $vehicle->id),
                            'type' => 'technical_review_expiration',
                            'vehicle_id' => $vehicle->id,
                            'expiration_date' => $vehicle->technical_review_expiration_date->format('Y-m-d'),
                        ]
                    );
                }
            }

            // --- Vencimiento TUCE ---
            if ($vehicle->tuce_expiration_date instanceof Carbon) {
                $daysRemaining = $today->diffInDays($vehicle->tuce_expiration_date, false);

                if ($daysRemaining <= $tuceThresholdDays && $daysRemaining >= 0) {
                    $this->notifyFilamentIfNecessary(
                        $adminUser,
                        $vehicle,
                        'tuce_expiration',
                        $tuceThresholdDays,
                        [
                            'title' => '¡Vencimiento de TUCE!',
                            'body' => "El TUCE del vehículo con placa {$vehicle->plate_number} (Marca: {$vehicle->brand}, Modelo: {$vehicle->model}) vence en {$daysRemaining} día(s).",
                            'icon' => 'heroicon-o-document-text',
                            'color' => 'info',
                            'url' => route('filament.admin.resources.vehicles.edit', $vehicle->id),
                            'type' => 'tuce_expiration',
                            'vehicle_id' => $vehicle->id,
                            'expiration_date' => $vehicle->tuce_expiration_date->format('Y-m-d'),
                        ]
                    );
                }
            }
        }

        Log::info('Comando app:check-vehicle-expirations finalizado.');
        $this->info('Verificación de vencimientos de vehículos completada.');
    }

    /**
     * Envía una notificación de Laravel si no existe una reciente o no leída para el mismo tipo/vehículo.
     *
     * @param User $notifiable
     * @param Vehicle $vehicle
     * @param string $notificationType Un identificador de cadena para el tipo de notificación (ej. 'soat_expiration').
     * @param int $thresholdDays El umbral de días que dispara la notificación (para evitar re-envíos cercanos).
     * @param \Illuminate\Notifications\Notification $notification La instancia de la notificación de Laravel a enviar (ej. SoatExpirationNotification).
     */
    protected function notifyFilamentIfNecessary(
        User $notifiable,
        Vehicle $vehicle,
        string $notificationType,
        int $thresholdDays,
        array $notificationData
    ): void {
        $recentThreshold = max(1, floor($thresholdDays / 2));
        $lastSentCutoff = Carbon::now()->subDays($recentThreshold);

        $existingNotification = $notifiable->notifications()
            ->where(function ($query) use ($notificationType, $vehicle) {
                $query->whereRaw("JSON_EXTRACT(data, '$.type') = ?", [$notificationType])
                    ->whereRaw("JSON_EXTRACT(data, '$.vehicle_id') = ?", [$vehicle->id]);
            })
            ->where(function ($query) use ($lastSentCutoff) {
                $query->whereNull('read_at')
                    ->orWhere('created_at', '>=', $lastSentCutoff);
            })
            ->first();

        if (!$existingNotification) {
            FilamentNotification::make()
                ->title($notificationData['title'])
                ->body($notificationData['body'])
                ->icon($notificationData['icon'])
                ->color($notificationData['color'])
                ->actions([
                    \Filament\Notifications\Actions\Action::make('ver')
                        ->label('Ver Vehículo')
                        ->url($notificationData['url'])
                        ->button(),
                ])
                ->sendToDatabase($notifiable);

            $this->info("Notificación enviada: '{$notificationType}' para vehículo con placa {$vehicle->plate_number} (ID: {$vehicle->id}).");
            Log::info("Notificación enviada: '{$notificationType}' para vehículo con placa {$vehicle->plate_number} (ID: {$vehicle->id}).");
        } else {
            $this->comment("Notificación '{$notificationType}' para vehículo con placa {$vehicle->plate_number} (ID: {$vehicle->id}) ya existe o fue enviada recientemente.");
            Log::info("Notificación '{$notificationType}' para vehículo con placa {$vehicle->plate_number} (ID: {$vehicle->id}) ya existe o fue enviada recientemente.");
        }
    }
}