<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VehicleResource\Pages;
use App\Filament\Resources\VehicleResource\RelationManagers;
use App\Models\Vehicle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\DatePicker; // Importa DatePicker
use Filament\Forms\Components\Select; // Para el driver_id

class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationLabel = 'Vehículos';
    protected static ?string $pluralModelLabel = 'Vehículos';
    protected static ?string $modelLabel = 'Vehículo';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationGroup = 'Entidades'; 

    /* public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('plate_number')
                    ->label('Número de Placa')
                    ->required(),
                Forms\Components\TextInput::make('brand')
                    ->label('Marca')
                    ->required(),
                Forms\Components\TextInput::make('model')
                    ->label('Modelo')
                    ->required(),                
                Forms\Components\TextInput::make('vehicle_certificate')
                    ->label('Certificado Vehicular')
                    ->nullable(),
            ]);
    } */
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
                            ->label('Certificado Vehicular')
                            ->nullable()
                            ->maxLength(255),                        
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
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
