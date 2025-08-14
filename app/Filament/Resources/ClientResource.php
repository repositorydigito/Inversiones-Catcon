<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientResource\Pages;
use App\Filament\Resources\ClientResource\RelationManagers;
use App\Models\Client;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?string $navigationLabel = 'Clientes';
    protected static ?string $pluralModelLabel = 'Clientes';
    protected static ?string $modelLabel = 'Cliente';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationGroup = 'Entidades';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Razón Social')
                    ->required(),
                Forms\Components\TextInput::make('comercial_name')
                    ->label('Nombre Comercial')
                    ->required()
                    ->nullable(),
                Forms\Components\Select::make('document_type')
                    ->label('Tipo de Documento')
                    ->options([
                        'DNI' => 'DNI',
                        'RUC' => 'RUC',
                    ])
                    ->default('RUC')
                    ->reactive()
                    ->required(),
                Forms\Components\TextInput::make('document_number')
                    ->label('Número de Documento')
                    ->required()
                    ->numeric()
                    ->rules(function (callable $get, ?string $operation, $record) {
                        $documentType = $get('document_type');
                        $rules = ['numeric'];
                        
                        // Validación de longitud según tipo de documento
                        if ($documentType === 'DNI') {
                            $rules[] = 'digits:8';
                        } elseif ($documentType === 'RUC') {
                            $rules[] = 'digits:11';
                        }
                        
                        // Validación de unicidad
                        $uniqueRule = Rule::unique('clients', 'document_number');
                        if ($operation === 'edit' && $record) {
                            $uniqueRule->ignore($record->id);
                        }
                        $rules[] = $uniqueRule;
                        
                        return $rules;
                    })                    
                    ->validationMessages([
                        'numeric' => 'El documento solo puede contener números.',
                        'digits' => function (callable $get) {
                            $documentType = $get('document_type');
                            if ($documentType === 'DNI') {
                                return 'El DNI debe tener exactamente 8 dígitos.';
                            } elseif ($documentType === 'RUC') {
                                return 'El RUC debe tener exactamente 11 dígitos.';
                            }
                            return 'Número de dígitos incorrecto.';
                        },
                        'unique' => 'Este número de documento ya existe en el sistema.',
                    ]),
                Forms\Components\TextInput::make('phone')
                    ->label('Teléfono')
                    ->tel()
                    ->nullable(),
                Forms\Components\TextInput::make('address')
                    ->label('Dirección')
                    ->nullable()
                    ->validationMessages([
                        'regex' => 'Ingrese un teléfono válido.',
                    ]),
                Forms\Components\TextInput::make('email')
                    ->label('Correo Electrónico')
                    ->email()
                    ->nullable(),
                Forms\Components\TextInput::make('days_to_pay')
                    ->label('Días para pagar')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(365)
                    ->nullable()
                    ->helperText('Importante para las Cuentas por Cobrar'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(),
                Tables\Columns\TextColumn::make('comercial_name')
                    ->label('Nombre Comercial')
                    ->searchable(),
                Tables\Columns\TextColumn::make('document_type')
                    ->label('Tipo de Documento')
                    ->searchable(),
                Tables\Columns\TextColumn::make('document_number')
                    ->label('Número de Documento')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone')
                    ->label('Teléfono')
                    ->searchable(),
                Tables\Columns\TextColumn::make('address')
                    ->label('Dirección')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Correo Electrónico')
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
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'edit' => Pages\EditClient::route('/{record}/edit'),
        ];
    }
}
