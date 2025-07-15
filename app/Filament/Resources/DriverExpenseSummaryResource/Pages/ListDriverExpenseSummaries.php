<?php

namespace App\Filament\Resources\DriverExpenseSummaryResource\Pages;

use App\Filament\Resources\DriverExpenseSummaryResource;
use Filament\Actions;
use App\Models\Driver;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;

class ListDriverExpenseSummaries extends ListRecords
{
    protected static string $resource = DriverExpenseSummaryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_summaries')
                ->label('Generar Resúmenes')
                ->icon('heroicon-o-calculator')
                ->color('success')
                ->form([
                    DatePicker::make('period_start')
                        ->label('Fecha Inicio')
                        ->required()
                        ->default(now()->startOfMonth()),
                    
                    DatePicker::make('period_end')
                        ->label('Fecha Fin')
                        ->required()
                        ->default(now()->endOfMonth()),
                    
                    Select::make('driver_id')
                        ->label('Conductor (Opcional)')
                        ->options(Driver::all()->pluck('full_name', 'id'))
                        ->placeholder('Todos los conductores'),
                ])
                ->action(function (array $data) {
                    $this->generateSummaries($data);
                })
                ->requiresConfirmation()
                ->modalDescription('Esto generará resúmenes de gastos para el período seleccionado.'),
        ];
    }

    protected function generateSummaries(array $data): void
    {
        $drivers = $data['driver_id'] 
            ? Driver::where('id', $data['driver_id'])->get()
            : Driver::all();

        foreach ($drivers as $driver) {
            $this->generateSummaryForDriver(
                $driver,
                $data['period_start'],
                $data['period_end']
            );
        }

        $this->redirect(static::getUrl());
    }

    protected function generateSummaryForDriver(Driver $driver, $startDate, $endDate): void
    {
        $summary = DriverExpenseSummary::firstOrCreate([
            'driver_id' => $driver->id,
            'period_start' => $startDate,
            'period_end' => $endDate,
        ], [
            'vehicle_id' => $driver->vehicle?->id,
        ]);

        DriverExpenseSummaryResource::regenerateSummary($summary);
    }
}
