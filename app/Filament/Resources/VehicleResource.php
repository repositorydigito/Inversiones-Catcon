<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VehicleResource\Pages;
use App\Filament\Resources\VehicleResource\RelationManagers;
use App\Models\Vehicle;
use App\Models\Driver;
use App\Models\TrafficTicket;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Tables\Actions\Action;

class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationLabel = 'Vehículos';
    protected static ?string $pluralModelLabel = 'Vehículos';
    protected static ?string $modelLabel = 'Vehículo';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationGroup = 'Entidades';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Datos del Vehículo')
                    ->description('Registre los datos generales del vehículo.')
                    ->schema([
                        Forms\Components\TextInput::make('plate_number')
                            ->label('Número de Placa')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\TextInput::make('brand')
                            ->label('Marca')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('model')
                            ->label('Modelo')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('vehicle_certificate')
                            ->label('Certificado Vehicular (TUCE)')
                            ->required()
                            ->minLength(10)
                            ->maxLength(15),

                        Forms\Components\Select::make('driver_id')
                            ->label('Conductor Asignado')
                            ->options(Driver::all()->mapWithKeys(function ($driver) {
                                return [$driver->id => "{$driver->first_name} {$driver->last_name} ({$driver->license_number})"];
                            }))
                            ->searchable()
                            ->nullable(),
                    ])->columns(2),

                Forms\Components\Section::make('Fechas de Vencimiento')
                    ->description('Registre las fechas importantes de vencimiento del vehículo.')
                    ->schema([
                        DatePicker::make('soat_expiration_date')
                            ->label('Vencimiento SOAT')
                            ->displayFormat('d/m/Y')
                            ->nullable(),
                        DatePicker::make('technical_review_expiration_date')
                            ->label('Vencimiento Revisión Técnica')
                            ->displayFormat('d/m/Y')
                            ->nullable(),
                        DatePicker::make('tuce_expiration_date')
                            ->label('Vencimiento TUCE')
                            ->displayFormat('d/m/Y')
                            ->nullable(),
                    ])->columns(2),

                Forms\Components\Section::make('Fotos de Papeletas (Vehículo)')
                    ->description('Adjunte aquí las fotos de papeletas asociadas a este vehículo.')
                    ->schema([
                        Forms\Components\Repeater::make('trafficTickets')
                            ->relationship('trafficTickets')
                            ->label('Papeletas')
                            ->addActionLabel('Añadir Papeleta')
                            ->collapsible()
                            ->itemLabel(function (array $state): string {
                                $imagePath = $state['image_path'] ?? null;
                                if (is_array($imagePath)) {
                                    $imagePath = $imagePath[0] ?? null;
                                }
                                return $imagePath ? basename($imagePath) : 'Nueva Papeleta';
                            })
                            ->schema([
                                Forms\Components\FileUpload::make('image_path')
                                    ->label('Foto de la Papeleta')
                                    ->image()
                                    ->directory('traffic-tickets/vehicles')
                                    ->preserveFilenames()
                                    ->visibility('public')
                                    ->nullable(),
                            ])
                            ->defaultItems(0)
                            ->minItems(0)
                            ->grid(2),

                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('plate_number')
                    ->label('Número de Placa')
                    ->searchable(),
                Tables\Columns\TextColumn::make('brand')
                    ->label('Marca')
                    ->searchable(),
                Tables\Columns\TextColumn::make('model')
                    ->label('Modelo')
                    ->searchable(),
                Tables\Columns\TextColumn::make('vehicle_certificate')
                    ->label('Certificado Vehicular')
                    ->searchable(),
                Tables\Columns\TextColumn::make('driver.full_name')
                    ->label('Conductor Asignado')
                    ->getStateUsing(function (Vehicle $record): string {
                        if ($record->driver) {
                            return $record->driver->first_name . ' ' . $record->driver->last_name;
                        }
                        return 'Sin asignar';
                    })
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Action::make('verPapeletas')
                    ->label('Ver Papeletas')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->modalHeading('Fotos de Papeletas')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(function (Vehicle $record) {
                        $imagenes = $record->trafficTickets->pluck('image_path')->filter()->values();
                        if ($imagenes->isEmpty()) {
                            return view('filament.resources.vehicle-resource.partials.papeletas-modal-empty');
                        }
                        return view('filament.resources.vehicle-resource.partials.papeletas-modal', [
                            'imagenes' => $imagenes,
                        ]);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicles::route('/'),
            'create' => Pages\CreateVehicle::route('/create'),
            'edit' => Pages\EditVehicle::route('/{record}/edit'),
        ];
    }
}
