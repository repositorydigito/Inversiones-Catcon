<?php

namespace App\Filament\Pages;

use App\Models\OperationalExpenseConfig;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;

class OperationalExpenseSettings extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.pages.operational-expense-settings';
    protected static ?string $title = 'Configuración de Gastos Operativos';
    protected static bool $shouldRegisterNavigation = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(OperationalExpenseConfig::query())
            ->columns([
                Tables\Columns\TextColumn::make('departure_point')
                    ->label('P1')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('departure_location')
                    ->label('Punto Partida')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('arrival_location')
                    ->label('Punto Llegada')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('destination_point')
                    ->label('P4')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tolls')
                    ->label('Peajes')
                    ->money('PEN')
                    ->formatStateUsing(fn ($state) => 'S/. ' . number_format($state, 3, '.', ','))
                    ->sortable(),
                Tables\Columns\TextColumn::make('loading_expenses')
                    ->label('G.Carga')
                    ->money('PEN')
                    ->formatStateUsing(fn ($state) => 'S/. ' . number_format($state, 3, '.', ','))
                    ->sortable(),
                Tables\Columns\TextColumn::make('variable_salary')
                    ->label('S.V.CH')
                    ->money('PEN')
                    ->formatStateUsing(fn ($state) => 'S/. ' . number_format($state, 3, '.', ','))
                    ->sortable(),
                Tables\Columns\TextColumn::make('operations_manager')
                    ->label('J.OPE')
                    ->money('PEN')
                    ->formatStateUsing(fn ($state) => 'S/. ' . number_format($state, 3, '.', ','))
                    ->sortable(),
                Tables\Columns\TextColumn::make('security')
                    ->label('SEG.')
                    ->money('PEN')
                    ->formatStateUsing(fn ($state) => 'S/. ' . number_format($state, 3, '.', ','))
                    ->sortable(),
                Tables\Columns\TextColumn::make('rate')
                    ->label('Tarifa')
                    ->money('PEN')
                    ->formatStateUsing(fn ($state) => 'S/. ' . number_format($state, 3, '.', ','))
                    ->sortable(),
                Tables\Columns\TextColumn::make('travel_allowances')
                    ->label('Viáticos')
                    ->money('PEN')
                    ->formatStateUsing(fn ($state) => 'S/. ' . number_format($state, 3, '.', ','))
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Nueva Configuración')
                    ->modalHeading('Crear Configuración de Gastos')
                    ->form([
                        Forms\Components\Section::make('Información de Ruta')
                            ->schema([
                                Forms\Components\TextInput::make('departure_point')
                                    ->label('P1 (Punto 1)')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('departure_location')
                                    ->label('Punto de Partida')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('arrival_location')
                                    ->label('Punto de Llegada')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('destination_point')
                                    ->label('P4 (Punto 4)')
                                    ->required()
                                    ->maxLength(255),
                            ])
                            ->columns(4),
                        Forms\Components\Section::make('Gastos Operativos')
                            ->schema([
                                Forms\Components\TextInput::make('tolls')
                                    ->label('Peajes')
                                    ->numeric()
                                    ->prefix('S/.')
                                    ->step(0.01)
                                    ->default(0),
                                Forms\Components\TextInput::make('loading_expenses')
                                    ->label('Gastos de Carga')
                                    ->numeric()
                                    ->prefix('S/.')
                                    ->step(0.01)
                                    ->default(0),
                                Forms\Components\TextInput::make('variable_salary')
                                    ->label('Sueldo Variable')
                                    ->numeric()
                                    ->prefix('S/.')
                                    ->step(0.01)
                                    ->default(0),
                                Forms\Components\TextInput::make('operations_manager')
                                    ->label('Jefe de Operaciones')
                                    ->numeric()
                                    ->prefix('S/.')
                                    ->step(0.01)
                                    ->default(0),
                                Forms\Components\TextInput::make('security')
                                    ->label('Seguridad')
                                    ->numeric()
                                    ->prefix('S/.')
                                    ->step(0.01)
                                    ->default(0),
                                Forms\Components\TextInput::make('rate')
                                    ->label('Tarifa')
                                    ->numeric()
                                    ->prefix('S/.')
                                    ->step(0.001)
                                    ->default(0),
                            ])
                            ->columns(3),
                        Forms\Components\Section::make('Viáticos')
                            ->schema([
                                Forms\Components\TextInput::make('travel_allowances')
                                    ->label('Viáticos')
                                    ->numeric()
                                    ->prefix('S/.')
                                    ->step(0.01)
                                    ->default(30.00),
                            ]),
                    ])
                    ->action(function (array $data) {
                        try {
                            OperationalExpenseConfig::create($data);
                            Notification::make()
                                ->title('Configuración creada exitosamente')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Error al crear configuración')
                                ->body('Esta ruta ya existe o hay un error en los datos.')
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->form([
                        Forms\Components\Section::make('Información de Ruta')
                            ->schema([
                                Forms\Components\TextInput::make('departure_point')
                                    ->label('P1 (Punto 1)')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('departure_location')
                                    ->label('Punto de Partida')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('arrival_location')
                                    ->label('Punto de Llegada')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('destination_point')
                                    ->label('P4 (Punto 4)')
                                    ->required()
                                    ->maxLength(255),
                            ])
                            ->columns(2),
                        Forms\Components\Section::make('Gastos Operativos')
                            ->schema([
                                Forms\Components\TextInput::make('tolls')
                                    ->label('Peajes')
                                    ->numeric()
                                    ->prefix('S/.')
                                    ->step(0.01)
                                    ->default(0),
                                Forms\Components\TextInput::make('loading_expenses')
                                    ->label('Gastos de Carga')
                                    ->numeric()
                                    ->prefix('S/.')
                                    ->step(0.01)
                                    ->default(0),
                                Forms\Components\TextInput::make('variable_salary')
                                    ->label('Sueldo Variable')
                                    ->numeric()
                                    ->prefix('S/.')
                                    ->step(0.01)
                                    ->default(0),
                                Forms\Components\TextInput::make('operations_manager')
                                    ->label('Jefe de Operaciones')
                                    ->numeric()
                                    ->prefix('S/.')
                                    ->step(0.01)
                                    ->default(0),
                                Forms\Components\TextInput::make('security')
                                    ->label('Seguridad')
                                    ->numeric()
                                    ->prefix('S/.')
                                    ->step(0.01)
                                    ->default(0),
                                Forms\Components\TextInput::make('rate')
                                    ->label('Tarifa')
                                    ->numeric()
                                    ->prefix('S/.')
                                    ->step(0.001)
                                    ->default(0),
                            ])
                            ->columns(3),
                        Forms\Components\Section::make('Viáticos')
                            ->schema([
                                Forms\Components\TextInput::make('travel_allowances')
                                    ->label('Viáticos')
                                    ->numeric()
                                    ->prefix('S/.')
                                    ->step(0.01)
                                    ->default(30.00),
                            ]),
                    ]),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([

            ])
            ->emptyStateHeading('No hay configuraciones de gastos')
            ->emptyStateDescription('Comienza agregando tu primera configuración de gastos operativos por ruta.')
            ->emptyStateIcon('heroicon-o-currency-dollar');
    }
}
