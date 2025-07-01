<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DespatchResource\Pages;
use App\Filament\Resources\DespatchResource\RelationManagers;
use App\Models\Client;
use App\Models\Company;
use App\Models\Despatch;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Models\MeasureUnit;
use App\Models\DespatchItem;
use App\Services\DespatchService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\Actions\Action; // Importar para acciones personalizadas
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;
use Filament\Notifications\Notification;
use Exception;

class DespatchResource extends Resource
{
    protected static ?string $model = Despatch::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationLabel = 'Guías de Remisión';
    protected static ?string $pluralModelLabel = 'Guías de Remisión';
    protected static ?string $modelLabel = 'Guía';
    //protected static ?int $navigationSort = 3;
    //protected static ?string $navigationGroup = 'Entidades';    

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Datos Generales de la Guía')
                    ->columns(3)
                    ->schema([                        
                        Select::make('company_id')
                            ->label('Remitente (Tu Empresa)')
                            ->options(Company::all()->pluck('name', 'id'))
                            ->required()
                            ->default(Company::first()?->id)
                            ->helperText('La empresa que actúa como remitente de esta guía.'),
                        
                        Select::make('document_type')
                            ->label('Tipo de Guía')
                            ->options([
                                '7' => 'Guía de Remisión Remitente',
                                '8' => 'Guía de Remisión Transportista',
                            ])
                            ->required()
                            ->default('8'),

                        TextInput::make('series')
                            ->label('Serie')
                            ->required()
                            ->maxLength(4)
                            ->default('E001') // Ejemplo de serie por defecto
                            ->helperText('Serie de la guía (ej. E001).'),

                        TextInput::make('number')
                            ->label('Número')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->default(fn () => Despatch::max('number') + 1) // Sugerir el siguiente número
                            ->helperText('Número correlativo de la guía.'),

                        DatePicker::make('emission_date')
                            ->label('Fecha de Emisión')
                            ->required()
                            ->default(now()),

                        DatePicker::make('transfer_start_date')
                            ->label('Fecha de Inicio de Traslado')
                            ->required()
                            ->default(now()), // Sugiere el mismo día

                        Select::make('total_gross_weight_unit_of_measure')
                            ->label('Unidad de Medida Peso')
                            ->options([
                                'KGM' => 'Kilogramos (KGM)',
                                'TNE' => 'Toneladas (TNE)',
                                // Agrega más si son necesarios según SUNAT/Nubefact
                            ])
                            ->required()
                            ->reactive()
                            ->default('KGM'),

                        TextInput::make('total_gross_weight')
                            ->label('Peso Bruto Total')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->suffix(fn (Forms\Get $get) => $get('total_gross_weight_unit_of_measure')),

                        Textarea::make('observations')
                            ->label('Observaciones')
                            ->columnSpanFull()
                            ->maxLength(255),
                    ]),

                Section::make('Información de Destinatario')
                    ->columns(2)
                    ->schema([
                        Select::make('client_id')
                            ->label('Destinatario (Cliente)')
                            ->options(Client::all()->pluck('name', 'id')) // Asume que tu modelo Client tiene un atributo 'full_name'
                            ->searchable()
                            ->required()
                            ->helperText('Selecciona al cliente que recibirá la mercadería.'),
                        // Los datos del destinatario (tipo_documento, numero, denominacion) se obtendrán del modelo Client.
                    ]),

                Section::make('Puntos de Partida y Llegada')
                    ->columns(2)
                    ->schema([
                        Group::make()
                            ->schema([
                                TextInput::make('departure_ubigeo')
                                    ->label('Ubigeo de Partida')
                                    ->required()
                                    ->maxLength(6)
                                    ->placeholder('Ej. 150101 (Lima, Lima, Lima)'), // Puedes integrar un selector de ubigeo si tienes uno
                                TextInput::make('departure_address')
                                    ->label('Dirección de Partida')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('departure_sunat_establishment_code')
                                    ->label('Código de Establecimiento SUNAT (Partida)')
                                    ->maxLength(4)
                                    ->placeholder('Ej. 0000')
                                    ->nullable()
                                    ->helperText('Opcional, código de SUNAT si aplica.'),
                            ]),
                        Group::make()
                            ->schema([
                                TextInput::make('arrival_ubigeo')
                                    ->label('Ubigeo de Llegada')
                                    ->required()
                                    ->maxLength(6)
                                    ->placeholder('Ej. 210101 (Piura, Piura, Piura)'),
                                TextInput::make('arrival_address')
                                    ->label('Dirección de Llegada')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('arrival_sunat_establishment_code')
                                    ->label('Código de Establecimiento SUNAT (Llegada)')
                                    ->maxLength(4)
                                    ->placeholder('Ej. 0000')
                                    ->nullable()
                                    ->helperText('Opcional, código de SUNAT si aplica.'),
                            ]),
                    ]),

                Section::make('Transporte Principal')
                    ->columns(2)
                    ->schema([
                        Select::make('vehicle_id')
                            ->label('Vehículo Principal')
                            ->options(Vehicle::all()->pluck('plate_number', 'id'))
                            ->searchable()
                            ->nullable() // Puede no haber un vehículo principal si es solo un conductor
                            ->helperText('Selecciona el vehículo principal del transporte.'),

                        Select::make('driver_id')
                            ->label('Conductor Principal')
                            ->options(Driver::all()->mapWithKeys(function ($driver) {
                                return [$driver->id => "{$driver->name} {$driver->last_name} ({$driver->license_number})"];
                            }))
                            ->searchable()
                            ->nullable()
                            ->helperText('Selecciona al conductor principal del transporte.'),
                    ]),

                Section::make('Información Condicional de Envío')
                    ->description(new HtmlString('<p class="text-sm">Selecciona el indicador de envío para mostrar campos adicionales si aplica.</p>'))
                    ->schema([
                        Select::make('sunat_envio_indicador')
                            ->label('Indicador de Envío SUNAT')
                            ->options([
                                ''    => 'Ninguno', // Opción por defecto
                                '01' => 'Pagador Flete: Remitente',
                                '02' => 'Pagador Flete: Subcontratista',
                                '03' => 'Pagador Flete: Tercero',
                                '04' => 'Retorno Vehículo/Envase Vacío',
                                '05' => 'Retorno Vehículo Vacío',
                                '06' => 'Traslado Vehículo M1L',
                            ])
                            ->live() // Hace que el campo reaccione a los cambios
                            ->nullable()
                            ->columnSpanFull()
                            ->helperText('Define el tipo de servicio de transporte.'),

                        // Campos para Subcontratista (si sunat_envio_indicador es '02')
                        Forms\Components\Fieldset::make('Datos del Subcontratista')
                            ->schema([
                                TextInput::make('subcontractor_document_type')
                                    ->label('Tipo Doc. Subcontratista')
                                    ->required()
                                    ->numeric()
                                    ->default(6) // Asume RUC por defecto
                                    ->hiddenOn('create') // Ocultar por defecto en creación
                                    ->disabled() // Deshabilitar si no se cumple la condición
                                    ->dehydrated(fn ($state) => filled($state)) // No guardar si está vacío
                                    ->helperText('Solo se acepta 6 (RUC) para subcontratista.'),
                                TextInput::make('subcontractor_document_number')
                                    ->label('Número Doc. Subcontratista')
                                    ->required()
                                    ->maxLength(11) // RUC tiene 11 dígitos
                                    ->hiddenOn('create')
                                    ->dehydrated(fn ($state) => filled($state)),
                                TextInput::make('subcontractor_denomination')
                                    ->label('Denominación Subcontratista')
                                    ->required()
                                    ->maxLength(255)
                                    ->hiddenOn('create')
                                    ->dehydrated(fn ($state) => filled($state)),
                            ])
                            ->visible(fn (Forms\Get $get): bool => $get('sunat_envio_indicador') === '02'), // Mostrar solo si el indicador es '02'

                        // Campos para Pagador del Servicio (si sunat_envio_indicador es '03')
                        Forms\Components\Fieldset::make('Datos del Pagador del Servicio')
                            ->schema([
                                Select::make('service_payer_document_type')
                                    ->label('Tipo Doc. Pagador')
                                    ->options([
                                        '6' => 'RUC',
                                        '1' => 'DNI',
                                        '4' => 'CARNET DE EXTRANJERÍA',
                                        '7' => 'PASAPORTE',
                                        'A' => 'CÉDULA DIPLOMÁTICA DE IDENTIDAD',
                                        '0' => 'NO DOMICILIADO, SIN RUC (EXPORTACIÓN)',
                                    ])
                                    ->required()
                                    ->hiddenOn('create')
                                    ->dehydrated(fn ($state) => filled($state)),
                                TextInput::make('service_payer_document_number')
                                    ->label('Número Doc. Pagador')
                                    ->required()
                                    ->maxLength(20) // Suficiente para todos los tipos
                                    ->hiddenOn('create')
                                    ->dehydrated(fn ($state) => filled($state)),
                                TextInput::make('service_payer_denomination')
                                    ->label('Denominación Pagador')
                                    ->required()
                                    ->maxLength(255)
                                    ->hiddenOn('create')
                                    ->dehydrated(fn ($state) => filled($state)),
                            ])
                            ->visible(fn (Forms\Get $get): bool => $get('sunat_envio_indicador') === '03'), // Mostrar solo si el indicador es '03'
                    ]),

                Section::make('Ítems de la Guía')
                    ->schema([
                        Repeater::make('items')
                            ->relationship('items') // Define la relación con el modelo DespatchItem
                            ->schema([
                                TextInput::make('code')
                                    ->label('Código')
                                    ->maxLength(20)
                                    ->nullable(),
                                TextInput::make('description')
                                    ->label('Descripción')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('quantity')
                                    ->label('Cantidad')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0.01)
                                    ->step(0.01),
                                Select::make('unit_of_measure_id') 
                                ->label('Unidad de Medida')
                                ->options(MeasureUnit::all()->pluck('description', 'id')) 
                                ->searchable()
                                ->required(),
                            ])
                            ->columns(4)
                            ->collapsible()
                            ->defaultItems(1)
                            ->reorderableWithButtons()
                            ->itemLabel(fn (array $state): ?string => $state['description'] ?? null) 
                            ->addActionLabel('Agregar Ítem'),
                    ]),

                Section::make('Vehículos y Conductores Secundarios')
                    ->description('Agrega vehículos o conductores adicionales que participan en el transporte.')
                    ->schema([
                        Repeater::make('Vehículos secundarios')
                            ->label('Vehículos Secundarios')
                            ->relationship('secondaryVehicles') // Relación Many-to-Many
                            ->schema([
                                Select::make('vehicle_id')
                                    ->label('Vehículo Secundario')
                                    ->options(Vehicle::all()->pluck('plate_number', 'id'))
                                    ->searchable()
                                    ->required(),
                            ])
                            ->columns(1)
                            ->collapsible()
                            ->reorderableWithButtons()
                            ->defaultItems(0)
                            ->addActionLabel('Agregar Vehículo Secundario'),

                        Repeater::make('Conductores secundarios')
                            ->label('Conductores Secundarios')
                            ->relationship('secondaryDrivers') // Relación Many-to-Many
                            ->schema([
                                Select::make('driver_id')
                                    ->label('Conductor Secundario')
                                    ->options(Driver::all()->mapWithKeys(function ($driver) {
                                        return [$driver->id => "{$driver->name} {$driver->last_name} ({$driver->license_number})"];
                                    }))
                                    ->searchable()
                                    ->required(),
                            ])
                            ->columns(1)
                            ->collapsible()
                            ->reorderableWithButtons()
                            ->defaultItems(0)
                            ->addActionLabel('Agregar Conductor Secundario'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('series')
                    ->searchable()
                    ->sortable()
                    ->label('Serie'),
                Tables\Columns\TextColumn::make('number')
                    ->numeric()
                    ->sortable()
                    ->label('Número'),
                Tables\Columns\TextColumn::make('company.name')
                    ->searchable()
                    ->sortable()
                    ->label('Remitente'),
                Tables\Columns\TextColumn::make('client.name')
                    ->searchable()
                    ->sortable()
                    ->label('Destinatario'),
                Tables\Columns\TextColumn::make('emission_date')
                    ->date()
                    ->sortable()
                    ->label('F. Emisión'),
                Tables\Columns\TextColumn::make('transfer_start_date')
                    ->date()
                    ->sortable()
                    ->label('F. Inicio Traslado'),
                /* Tables\Columns\IconColumn::make('accepted_by_sunat')
                    ->label('Aceptado SUNAT')
                    ->boolean(), */
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->label('Estado SUNAT')
                    ->getStateUsing(function (Despatch $record): string {
                        // Solo verificamos accepted_by_sunat
                        if ($record->accepted_by_sunat) {
                            return 'Aceptado';
                        }
                        return 'Generado';
                    })
                    ->colors([
                        'success' => 'Aceptado', // Verde para Aceptado
                        'warning' => 'Generado', // Amarillo/Naranja para Generado
                    ])
                    ->icons([
                        'heroicon-s-check-circle' => 'Aceptado',
                        'heroicon-s-clock' => 'Generado',
                    ])
                    //->tooltip(fn (Despatch $record): ?string => $record->sunat_description)
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('sunat_response_code')
                    ->label('Cód. Resp. SUNAT')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('sendToNubefact')
                    ->label('Enviar a Nubefact')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->color('success')
                    ->action(function (Despatch $record, DespatchService $nubefactService) { // Inyecta el servicio aquí
                        try {
                            $response = $nubefactService->sendDespatch($record);

                            // Si la función sendDespatch lanza una excepción en caso de error,
                            // no se llegará a esta parte en caso de fallo,
                            // sino al bloque catch de la acción.
                            Notification::make()
                                ->title('Guía de Remisión Enviada')
                                ->body("La guía #{$record->series}-{$record->number} ha sido procesada por SUNAT. Estado: " . ($record->accepted_by_sunat ? 'ACEPTADA' : 'RECHAZADA'))
                                ->success() // O 'warning'/'danger' basado en $record->accepted_by_sunat
                                ->send();

                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Error al Enviar Guía de Remisión')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }

                        $record->refresh(); // Recargar datos para mostrar el estado actualizado
                    })
                    ->visible(fn (Despatch $record): bool => !$record->accepted_by_sunat), // Muestra solo si no ha sido aceptada aún
                                
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
            'index' => Pages\ListDespatches::route('/'),
            'create' => Pages\CreateDespatch::route('/create'),
            'edit' => Pages\EditDespatch::route('/{record}/edit'),
        ];
    }    
}
