<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverResource\Pages;
use App\Filament\Resources\DriverResource\RelationManagers;
use App\Models\Driver;
use App\Models\TrafficTicket;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Tables\Actions\Action;

class DriverResource extends Resource
{
    protected static ?string $model = Driver::class;
    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationLabel = 'Conductores';
    protected static ?string $pluralModelLabel = 'Conductores';
    protected static ?string $modelLabel = 'Conductor';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationGroup = 'Entidades';     

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('first_name')
                    ->label('Nombres')
                    ->required(),
                Forms\Components\TextInput::make('last_name')
                    ->label('Apellidos')
                    ->required(),
                Forms\Components\Select::make('document_type')
                    ->label('Tipo de Documento')
                    ->options([
                        'DNI' => 'DNI',
                        'RUC' => 'RUC',
                    ])
                    ->default('DNI')
                    ->required(),
                Forms\Components\TextInput::make('document_number')
                    ->label('Número de Documento')
                    ->required(),
                Forms\Components\TextInput::make('license_number')
                    ->label('Número de Licencia')
                    ->minLength(9)
                    ->required(),
                Forms\Components\TextInput::make('phone')
                    ->label('Teléfono')
                    ->nullable(),
                
                Forms\Components\Section::make('Fotos de Papeletas (Conductor)')
                    ->description('Adjunte aquí las fotos de papeletas asociadas a este conductor.')
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
                                    ->directory('traffic-tickets/drivers')
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
                Tables\Columns\TextColumn::make('first_name')
                    ->label('Nombres')
                    ->searchable(),
                Tables\Columns\TextColumn::make('last_name')
                    ->label('Apellidos')
                    ->searchable(),
                Tables\Columns\TextColumn::make('document_type')
                    ->label('Tipo de Documento')
                    ->searchable(),
                Tables\Columns\TextColumn::make('document_number')
                    ->label('Número de Documento')
                    ->searchable(),
                Tables\Columns\TextColumn::make('license_number')
                    ->label('Número de Licencia')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone')
                    ->label('Teléfono')
                    ->searchable(),
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
                    ->modalContent(function (\App\Models\Driver $record) {
                        $imagenes = $record->trafficTickets->pluck('image_path')->filter()->values();
                        if ($imagenes->isEmpty()) {
                            return view('filament.resources.driver-resource.partials.papeletas-modal-empty');
                        }
                        return view('filament.resources.driver-resource.partials.papeletas-modal', [
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
            'index' => Pages\ListDrivers::route('/'),
            'create' => Pages\CreateDriver::route('/create'),
            'edit' => Pages\EditDriver::route('/{record}/edit'),
        ];
    }
}
