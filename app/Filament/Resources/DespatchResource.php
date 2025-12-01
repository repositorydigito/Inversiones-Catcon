<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DespatchResource\Pages;
use App\Filament\Resources\DespatchResource\RelationManagers;
use App\Models\Client;
use App\Models\Company;
use App\Models\Despatch;
use App\Models\Driver;
use App\Models\FrequentLocation;
use App\Models\Vehicle;
use App\Models\MeasureUnit;
use App\Models\Service;
use App\Models\DespatchItem;
use App\Models\OperationalExpenseConfig;
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
use Filament\Forms\Components\FileUpload;
use App\Services\DespatchImport\SunatXmlImportService;
use Filament\Tables\Actions\Action as TableAction;
use Exception;

class DespatchResource extends Resource
{
    protected static ?string $model = Despatch::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationLabel = 'Guías de Remisión';
    protected static ?string $pluralModelLabel = 'Guías de Remisión';
    protected static ?string $modelLabel = 'Guía';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Datos del Remitente')
                    ->columns(4)
                    ->schema([
                        Select::make('sender_client_id')
                            ->label('Razón Social')
                            ->options(Client::all()->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->afterStateUpdated(function ($state, $set) {
                                $client = Client::find($state);
                                if ($client) {
                                    $set('sender_document_number', $client->document_number);
                                } else {
                                    $set('sender_document_number', null);
                                }
                            })
                            ->live(),
                        TextInput::make('sender_document_number')
                            ->label('RUC')
                            ->required()
                            ->readOnly()
                            ->afterStateHydrated(function ($component, $state, $record) {
                                if ($record && $record->senderClient) {
                                    $component->state($record->senderClient->document_number);
                                }
                            }),
                        /* TextInput::make('departure_address')
                            ->label('Punto de Partida')
                            ->required()
                            ->columnSpan(2)
                            ->maxLength(255), */
                        Select::make('departure_address')
                            ->label('Punto de Partida')
                            ->required()
                            ->columnSpan(2)
                            ->searchable()
                            ->options(FrequentLocation::where('is_active', true)->orderBy('name')->pluck('name', 'name'))
                            ->allowHtml(false)
                            ->createOptionForm([
                                TextInput::make('manual_address')
                                    ->label('Dirección Manual')
                                    ->required()
                                    ->maxLength(255)
                                    ->helperText('Esta dirección NO se guardará como frecuente'),
                            ])
                            ->createOptionUsing(function (array $data) {
                                return $data['manual_address']; // Solo retorna el valor, no lo guarda en BD
                            })
                            ->helperText('Selecciona una ubicación frecuente o usa "Crear nueva opción" para escribir manualmente'),
                        /* TextInput::make('departure_sunat_establishment_code')
                            ->label('Código de Establecimiento (Partida)')
                            ->maxLength(4)
                            ->default('0000')
                            ->required(), */
                        Forms\Components\Fieldset::make('Ubigeo de Partida')
                            ->schema([
                                Select::make('departure_departamento')
                                    ->label('Departamento')
                                    ->options(app(\App\Services\UbigeoService::class)->getDepartamentos())
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        $set('departure_provincia', null);
                                        $set('departure_distrito', null);
                                        $set('departure_ubigeo', null);
                                    })
                                    ->placeholder('Seleccionar departamento')
                                    ->required(),

                                Select::make('departure_provincia')
                                    ->label('Provincia')
                                    ->options(function (callable $get) {
                                        $departamento = $get('departure_departamento');
                                        if (!$departamento) return [];
                                        return app(\App\Services\UbigeoService::class)->getProvincias($departamento);
                                    })
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        $set('departure_distrito', null);
                                        $set('departure_ubigeo', null);
                                    })
                                    ->placeholder('Seleccionar provincia')
                                    ->disabled(fn (callable $get) => !$get('departure_departamento'))
                                    ->required(),

                                Select::make('departure_distrito')
                                    ->label('Distrito')
                                    ->options(function (callable $get) {
                                        $departamento = $get('departure_departamento');
                                        $provincia = $get('departure_provincia');
                                        if (!$departamento || !$provincia) return [];
                                        return app(\App\Services\UbigeoService::class)->getDistritos($departamento, $provincia);
                                    })
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        $departamento = $get('departure_departamento');
                                        $provincia = $get('departure_provincia');
                                        $distrito = $state;

                                        if ($departamento && $provincia && $distrito) {
                                            $ubigeoCode = app(\App\Services\UbigeoService::class)
                                                ->generateUbigeoCode($departamento, $provincia, $distrito);
                                            $set('departure_ubigeo', $ubigeoCode);
                                        }
                                    })
                                    ->placeholder('Seleccionar distrito')
                                    ->disabled(fn (callable $get) => !$get('departure_provincia'))
                                    ->required(),

                                // Campo oculto que almacena el código ubigeo final
                                Forms\Components\Hidden::make('departure_ubigeo'),
                            ])
                            ->columnSpan(3)
                            ->columns(3),
                    ]),
                // CAMPO OCULTO PARA COMPANY_ID
                Forms\Components\Hidden::make('company_id')
                    ->default(1) // ID de tu empresa por defecto
                    ->dehydrated(true), // Importante: sí guardar en BD
                Section::make('Datos del Destinatario')
                    ->columns(4)
                    ->schema([
                        Select::make('client_id')
                            ->label('Razón Social')
                            ->options(Client::all()->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->afterStateUpdated(function ($state, $set) {
                                $client = Client::find($state);
                                if ($client) {
                                    $set('client_document_number', $client->document_number);
                                } else {
                                    $set('client_document_number', null);
                                }
                            })
                            ->live(),
                        TextInput::make('client_document_number')
                            ->label('RUC')
                            ->required()
                            ->readOnly()
                            ->afterStateHydrated(function ($component, $state, $record) {
                                if ($record && $record->client) {
                                    $component->state($record->client->document_number);
                                }
                            }),
                        Select::make('arrival_address')
                            ->label('Punto de Llegada')
                            ->required()
                            ->columnSpan(2)
                            ->searchable()
                            ->options(FrequentLocation::where('is_active', true)->orderBy('name')->pluck('name', 'name'))
                            ->allowHtml(false)
                            ->createOptionForm([
                                TextInput::make('manual_address')
                                    ->label('Dirección Manual')
                                    ->required()
                                    ->maxLength(255)
                                    ->helperText('Esta dirección NO se guardará como frecuente'),
                            ])
                            ->createOptionUsing(function (array $data) {
                                return $data['manual_address']; // Solo retorna el valor, no lo guarda en BD
                            })
                            ->helperText('Selecciona una ubicación frecuente o usa "Crear nueva opción" para escribir manualmente'),
                        /* TextInput::make('arrival_address')
                            ->label('Punto de Llegada')
                            ->required()
                            ->columnSpan(2)
                            ->maxLength(255), */
                        /* TextInput::make('arrival_sunat_establishment_code')
                            ->label('Código de Establecimiento (Llegada)')
                            ->maxLength(4)
                            ->default('0000')
                            ->required(),   */
                        Forms\Components\Fieldset::make('Ubigeo de Llegada')
                            ->schema([
                                Select::make('arrival_departamento')
                                    ->label('Departamento')
                                    ->options(app(\App\Services\UbigeoService::class)->getDepartamentos())
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        $set('arrival_provincia', null);
                                        $set('arrival_distrito', null);
                                        $set('arrival_ubigeo', null);
                                    })
                                    ->placeholder('Seleccionar departamento')
                                    ->required(),

                                Select::make('arrival_provincia')
                                    ->label('Provincia')
                                    ->options(function (callable $get) {
                                        $departamento = $get('arrival_departamento');
                                        if (!$departamento) return [];
                                        return app(\App\Services\UbigeoService::class)->getProvincias($departamento);
                                    })
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        $set('arrival_distrito', null);
                                        $set('arrival_ubigeo', null);
                                    })
                                    ->placeholder('Seleccionar provincia')
                                    ->disabled(fn (callable $get) => !$get('arrival_departamento'))
                                    ->required(),

                                Select::make('arrival_distrito')
                                    ->label('Distrito')
                                    ->options(function (callable $get) {
                                        $departamento = $get('arrival_departamento');
                                        $provincia = $get('arrival_provincia');
                                        if (!$departamento || !$provincia) return [];
                                        return app(\App\Services\UbigeoService::class)->getDistritos($departamento, $provincia);
                                    })
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        $departamento = $get('arrival_departamento');
                                        $provincia = $get('arrival_provincia');
                                        $distrito = $state;

                                        if ($departamento && $provincia && $distrito) {
                                            $ubigeoCode = app(\App\Services\UbigeoService::class)
                                                ->generateUbigeoCode($departamento, $provincia, $distrito);
                                            $set('arrival_ubigeo', $ubigeoCode);
                                        }
                                    })
                                    ->placeholder('Seleccionar distrito')
                                    ->disabled(fn (callable $get) => !$get('arrival_provincia'))
                                    ->required(),

                                // Campo oculto que almacena el código ubigeo final
                                Forms\Components\Hidden::make('arrival_ubigeo'),
                            ])
                            ->columnSpan(3)
                            ->columns(3),
                    ]),
                Section::make('Documentos Relacionados')
                    ->description('Guías de remisión del remitente u otros documentos que sustentan el traslado')
                    ->schema([
                        Repeater::make('relatedDocuments')
                            ->relationship('relatedDocuments')
                            ->label('')
                            ->schema([
                                Select::make('document_type')
                                    ->label('Tipo de Documento')
                                    ->options([
                                        '09' => 'Guía de Remisión Remitente',
                                        '31' => 'Guía de Remisión Transportista',
                                        '01' => 'Factura',
                                        '03' => 'Boleta de Venta',
                                        '07' => 'Nota de Crédito',
                                        '08' => 'Nota de Débito',
                                    ])
                                    ->required()
                                    ->default('09')
                                    ->columnSpan(2),

                                TextInput::make('series')
                                    ->label('Serie')
                                    ->required()
                                    ->maxLength(4)
                                    ->minLength(4)
                                    ->rules(['size:4'])
                                    ->columnSpan(1),

                                TextInput::make('number')
                                    ->label('Número')
                                    ->required()
                                    ->numeric()
                                    ->columnSpan(1),
                            ])
                            ->columns(4)
                            ->defaultItems(0)
                            ->reorderableWithButtons()
                            ->itemLabel(fn (array $state): ?string =>
                                isset($state['series'], $state['number'])
                                    ? "{$state['series']}-{$state['number']}"
                                    : null
                            )
                            ->addActionLabel('Agregar')
                            ->collapsible()
                            ->helperText('⚠️ IMPORTANTE: Los documentos relacionados deben estar previamente registrados en SUNAT'),
                    ])
                    ->collapsible(),
                Section::make('Información General')
                    ->columns(3)
                    ->schema([
                        Select::make('document_type')
                            ->label('Tipo de Guía')
                            ->options([
                                // '7' => 'Guía de Remisión Remitente',
                                '8' => 'Guía de Remisión Transportista',
                            ])
                            ->required()
                            ->default('8'),

                        TextInput::make('series')
                            ->label('Serie')
                            ->required()
                            ->maxLength(4)
                            ->default('V001'),

                        TextInput::make('number')
                            ->label('Número')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->default(fn () => Despatch::max('number') + 1), // Sugerir el siguiente número

                        DatePicker::make('emission_date')
                            ->label('Fecha de Emisión')
                            ->required()
                            ->default(now())
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set, callable $get, $record) {
                                // Recalcular viáticos cuando cambie la fecha
                                $calculatedValue = static::calculateTravelAllowances($get, $record);
                                $set('travel_allowances', $calculatedValue);
                            }),

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

                        TextInput::make('net_weight')
                            ->label('Peso Neto')
                            ->numeric()
                            ->step(0.01)
                            ->suffix(fn (Forms\Get $get) => $get('total_gross_weight_unit_of_measure'))
                            ->nullable(),

                        Textarea::make('observations')
                            ->label('Observaciones')
                            ->maxLength(100)
                            ->nullable(),
                    ]),

                Section::make('Puntos de Ruta')
                    ->description('Información que se puede personalizar desde Configuraciones')
                    ->schema([
                        Select::make('loading_point')
                            ->label('Punto 1')
                            ->nullable()
                            ->options(OperationalExpenseConfig::distinct()->pluck('departure_point', 'departure_point'))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $get, callable $set) {
                                static::autoCompleteFromFourFields($get, $set);
                            }),

                        Select::make('departure_location')
                            ->label('Punto de Partida')
                            ->nullable()
                            ->options(OperationalExpenseConfig::distinct()->pluck('departure_location', 'departure_location'))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $get, callable $set) {
                                static::autoCompleteFromFourFields($get, $set);
                            }),

                        Select::make('arrival_location')
                            ->label('Punto de Llegada')
                            ->nullable()
                            ->options(OperationalExpenseConfig::distinct()->pluck('arrival_location', 'arrival_location'))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $get, callable $set) {
                                static::autoCompleteFromFourFields($get, $set);
                            }),

                        Select::make('unloading_point')
                            ->label('Punto 4')
                            ->nullable()
                            ->options(OperationalExpenseConfig::distinct()->pluck('destination_point', 'destination_point'))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $get, callable $set) {
                                static::autoCompleteFromFourFields($get, $set);
                            }),
                    ])
                    ->columns(4),

                Section::make('Datos de Pagador del Flete')
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
                            ->default('01')
                            ->live()
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
                            ->relationship('items')
                            ->schema([
                                Select::make('service_id')
                                    ->label('Servicio')
                                    ->options(function () {
                                        return Service::active()
                                            ->orderBy('code')
                                            ->get()
                                            ->mapWithKeys(function ($service) {
                                                return [$service->id => "{$service->code} - {$service->name}"];
                                            });
                                    })
                                    ->searchable()
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        if ($state) {
                                            $service = Service::find($state);
                                            if ($service) {
                                                $set('code', $service->code);
                                                $set('description', $service->name);
                                            }
                                        }
                                    })
                                    ->placeholder('Seleccionar servicio...')
                                    ->nullable()
                                    ->columnSpan(2),
                                TextInput::make('code')
                                    ->label('Código')
                                    ->readOnly()
                                    ->dehydrated(),
                                TextInput::make('description')
                                    ->label('Descripción')
                                    ->maxLength(255)
                                    ->readOnly()
                                    ->dehydrated(),
                                TextInput::make('quantity')
                                    ->label('Cantidad')
                                    ->numeric()
                                    ->nullable()
                                    ->minValue(0.01)
                                    ->step(0.01),
                                Select::make('unit_of_measure_id')
                                ->label('Unidad de Medida')
                                ->options(MeasureUnit::all()->pluck('description', 'id'))
                                ->searchable()
                                ->nullable()
                                ->default(function () {
                                    return MeasureUnit::where('code', 'ZZ')->first()->id;
                                }),
                            ])
                            ->columns(6)
                            ->defaultItems(0)
                            ->reorderableWithButtons()
                            ->itemLabel(fn (array $state): ?string => $state['description'] ?? null)
                            ->addActionLabel('Agregar Ítem'),
                    ]),

                Section::make('Transporte Principal')
                    ->description('Selecciona el vehículo principal. Su conductor asignado se incluirá automáticamente.')
                    ->schema([
                        Select::make('vehicle_id')
                            ->label('Vehículo Principal')
                            ->options(Vehicle::with('driver')->get()->mapWithKeys(function ($vehicle) {
                                $driverInfo = $vehicle->driver
                                    ? " → 👨‍💼 {$vehicle->driver->first_name} {$vehicle->driver->last_name} ({$vehicle->driver->license_number})"
                                    : " → ⚠️ Sin conductor asignado";
                                return [$vehicle->id => "🚛 {$vehicle->plate_number}{$driverInfo}"];
                            }))
                            ->searchable()
                            ->nullable()
                            ->live() // ✅ CLAVE: Usar live() en lugar de reactive()
                            ->afterStateUpdated(function ($state, callable $set, callable $get, $record) {
                                if ($state) {
                                    $vehicle = Vehicle::with('driver')->find($state);
                                    if ($vehicle && $vehicle->driver) {
                                        $set('driver_id', $vehicle->driver->id);

                                        // ✅ RECALCULAR VIÁTICOS cuando cambie el conductor
                                        $calculatedValue = static::calculateTravelAllowances($get, $record);
                                        $set('travel_allowances', $calculatedValue);

                                        // ✅ VALIDACIÓN EN TIEMPO REAL: Remover de secundarios automáticamente
                                        $currentSecondary = $get('secondaryVehicles') ?? [];
                                        if (in_array($state, $currentSecondary)) {
                                            $newSecondary = array_filter($currentSecondary, fn($id) => $id != $state);
                                            $set('secondaryVehicles', array_values($newSecondary));

                                            Notification::make()
                                                ->title('Vehículo removido de secundarios')
                                                ->body("🚛 {$vehicle->plate_number} fue removido de vehículos secundarios porque ahora es el principal")
                                                ->info()
                                                ->send();
                                        }
                                    } else {
                                        $set('driver_id', null);
                                        $set('travel_allowances', 0); // Sin conductor = sin viáticos

                                        if ($vehicle && !$vehicle->driver) {
                                            Notification::make()
                                                ->title('Vehículo sin conductor')
                                                ->body("⚠️ {$vehicle->plate_number} no tiene conductor asignado. Asigna un conductor en el módulo de Vehículos.")
                                                ->warning()
                                                ->persistent()
                                                ->send();
                                        }
                                    }
                                } else {
                                    $set('driver_id', null);
                                    $set('travel_allowances', 0);
                                }
                            })
                            ->helperText('El conductor asignado al vehículo se seleccionará automáticamente')
                            ->placeholder('Seleccionar vehículo principal...'),

                        Select::make('driver_id')
                            ->label('Conductor Asignado')
                            ->options(Driver::all()->mapWithKeys(function ($driver) {
                                return [$driver->id => "{$driver->first_name} {$driver->last_name} ({$driver->license_number})"];
                            }))
                            ->disabled()
                            ->dehydrated()
                            ->placeholder('Se asignará automáticamente')
                            ->helperText('Conductor del vehículo seleccionado'),
                    ])
                    ->columns(2),

                // Reemplazar la sección "Transporte Secundario" con validaciones reactivas:

                Section::make('Transporte Secundario')
                    ->description('Selecciona hasta 2 vehículos secundarios. Sus conductores asignados se incluirán automáticamente.')
                    ->schema([
                        Forms\Components\CheckboxList::make('secondaryVehicles')
                            ->label('Vehículos Secundarios (máximo 2)')
                            ->relationship('secondaryVehicles', 'plate_number')
                            ->options(Vehicle::with('driver')->get()->mapWithKeys(function ($vehicle) {
                                $driverInfo = $vehicle->driver
                                    ? " → 👨‍💼{$vehicle->driver->first_name} {$vehicle->driver->last_name}"
                                    : " → ⚠️ Sin conductor asignado";
                                return [$vehicle->id => "🚛 {$vehicle->plate_number}{$driverInfo}"];
                            }))
                            ->descriptions(Vehicle::with('driver')->get()->mapWithKeys(function ($vehicle) {
                                if ($vehicle->driver) {
                                    return [$vehicle->id => "Licencia: {$vehicle->driver->license_number} | {$vehicle->brand} {$vehicle->model}"];
                                }
                                return [$vehicle->id => "⚠️ Este vehículo necesita un conductor asignado | {$vehicle->brand} {$vehicle->model}"];
                            }))
                            ->searchable()
                            ->columns(1)
                            ->live() // ✅ CLAVE: Validación en tiempo real
                            ->afterStateUpdated(function ($state, callable $get, callable $set) {
                                if (!is_array($state)) return;

                                $mainVehicleId = $get('vehicle_id');
                                $mainDriverId = $get('driver_id');
                                $validatedVehicles = [];
                                $errorMessages = [];

                                foreach ($state as $vehicleId) {
                                    // ✅ VALIDACIÓN 1: No puede ser el vehículo principal
                                    if ($mainVehicleId && $vehicleId == $mainVehicleId) {
                                        $vehicle = Vehicle::find($vehicleId);
                                        $errorMessages[] = "🚛 {$vehicle?->plate_number}: No puede ser principal y secundario a la vez";
                                        continue;
                                    }

                                    // ✅ VALIDACIÓN 2: Debe tener conductor asignado
                                    $vehicle = Vehicle::with('driver')->find($vehicleId);
                                    if (!$vehicle || !$vehicle->driver) {
                                        $errorMessages[] = "🚛 {$vehicle?->plate_number}: Sin conductor asignado";
                                        continue;
                                    }

                                    // ✅ VALIDACIÓN 3: No puede tener el mismo conductor que el principal
                                    /* if ($mainDriverId && $vehicle->driver->id == $mainDriverId) {
                                        $errorMessages[] = "🚛 {$vehicle->plate_number}: Su conductor ya es el conductor principal";
                                        continue;
                                    } */

                                    // ✅ VALIDACIÓN 4: No duplicados
                                    if (in_array($vehicleId, $validatedVehicles)) {
                                        continue;
                                    }

                                    $validatedVehicles[] = $vehicleId;

                                    // ✅ VALIDACIÓN 5: Máximo 2 vehículos
                                    if (count($validatedVehicles) >= 2) {
                                        if (count($state) > 2) {
                                            $errorMessages[] = "Límite SUNAT: Solo 2 vehículos secundarios máximo";
                                        }
                                        break;
                                    }
                                }

                                // ✅ APLICAR CORRECCIONES AUTOMÁTICAMENTE
                                if ($validatedVehicles !== $state) {
                                    $set('secondaryVehicles', $validatedVehicles);
                                }

                                // ✅ MOSTRAR ERRORES SI EXISTEN
                                if (!empty($errorMessages)) {
                                    Notification::make()
                                        ->title('Selecciones corregidas automáticamente')
                                        ->body('• ' . implode('<br>• ', $errorMessages))
                                        ->warning()
                                        ->send();
                                }
                            })
                            ->rules(['max:2'])
                            ->validationAttribute('vehículos secundarios'),
                    ])
                    ->collapsible()
                    ->collapsed(false),
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
                    ->searchable()
                    ->sortable()
                    ->label('Número'),
                Tables\Columns\TextColumn::make('company.name')
                    ->searchable()
                    ->sortable()
                    ->label('Transportista'),
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
                    ->sortable(),
                Tables\Columns\TextColumn::make('sunat_response_code')
                    ->label('Cód. Resp. SUNAT')
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
                // Acción para consultar estado en SUNAT directo
                Tables\Actions\Action::make('consultSunatStatus')
                    ->label('SUNAT')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->action(function (Despatch $record) {
                        try {
                            $sunatService = app(\App\Services\SunatDespatchService::class);
                            $response = $sunatService->consultDespatchStatus($record);

                            if ($response['success']) {
                                $status = $record->accepted_by_sunat ? 'ACEPTADA' : 'PENDIENTE/RECHAZADA';

                                Notification::make()
                                    ->title('✅ Consulta Exitosa')
                                    ->body("Estado: {$status}")
                                    ->success()
                                    ->send();
                            } else {
                                // Manejar respuestas con errores
                                $sunatResponse = $response['sunat_response'] ?? [];

                                if (isset($sunatResponse['error'])) {
                                    $error = $sunatResponse['error'];
                                    $errorMessage = "Error {$error['numError']}: {$error['desError']}";

                                    Notification::make()
                                        ->title('❌ Error SUNAT')
                                        ->body($errorMessage)
                                        ->danger()
                                        ->persistent() // Mantener visible hasta que el usuario la cierre
                                        ->send();
                                } else {
                                    $codRespuesta = $sunatResponse['codRespuesta'] ?? 'Desconocido';

                                    Notification::make()
                                        ->title('⚠️ Estado No Aceptado')
                                        ->body("Código de respuesta: {$codRespuesta}")
                                        ->warning()
                                        ->send();
                                }
                            }

                        } catch (Exception $e) {
                            Notification::make()
                                ->title('❌ Error de Conexión')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }

                        $record->refresh();
                    })
                    ->visible(fn (Despatch $record): bool => !$record->accepted_by_sunat && !is_null($record->sunat_ticket)),

                // Acción para reenviar a SUNAT (si falló el envío inicial)
                Tables\Actions\Action::make('resendToSunat')
                    ->label('Reenviar')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->action(function (Despatch $record) {
                        try {
                            $sunatService = app(\App\Services\SunatDespatchService::class);
                            $response = $sunatService->sendDespatch($record);

                            if ($response['success']) {
                                Notification::make()
                                    ->title('🎉 Reenviado Exitosamente')
                                    ->body("Ticket: {$response['ticket']}")
                                    ->success()
                                    ->send();
                            }

                        } catch (Exception $e) {
                            Notification::make()
                                ->title('❌ Error al Reenviar')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }

                        $record->refresh();
                    })
                    ->visible(fn (Despatch $record): bool => !$record->accepted_by_sunat && (is_null($record->sunat_ticket) || !empty($record->sunat_soap_error)))
                    ->requiresConfirmation()
                    ->modalDescription('¿Está seguro de reenviar esta guía a SUNAT?'),

                /* Tables\Actions\Action::make('downloadPdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->url(fn (Despatch $record): ?string => $record->cdr_pdf_url ?: $record->enlace_del_pdf)
                    ->openUrlInNewTab()
                    ->visible(fn (Despatch $record): bool => $record->accepted_by_sunat && ($record->cdr_pdf_url || $record->enlace_del_pdf)), */

                Tables\Actions\Action::make('downloadPdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->action(function (Despatch $record) {
                        try {
                            $sunatService = app(\App\Services\SunatDespatchService::class);
                            $result = $sunatService->downloadCdrPdf($record);

                            if ($result['success']) {
                                $fileName = "GRE_{$record->series}-{$record->number}.pdf";

                                return response()->streamDownload(function () use ($result) {
                                    echo $result['content'];
                                }, $fileName, [
                                    'Content-Type' => 'application/pdf',
                                    'Content-Length' => $result['size'],
                                ]);
                            } else {
                                // Mensaje específico según el tipo de error
                                $errorMessage = static::getErrorMessage($result['error']);

                                Notification::make()
                                    ->title('No se pudo descargar el PDF')
                                    ->body($errorMessage)
                                    ->warning()
                                    ->persistent()
                                    ->send();
                            }

                        } catch (\Exception $e) {
                            // En producción, mostrar mensaje genérico amigable
                            Notification::make()
                                ->title('Error temporal')
                                ->body('Los servidores de SUNAT están temporalmente lentos. Por favor, intente nuevamente en unos minutos.')
                                ->warning()
                                ->persistent()
                                ->send();
                        }
                    })
                    ->visible(fn (Despatch $record): bool => $record->accepted_by_sunat && ($record->cdr_pdf_url || $record->enlace_del_pdf)),

                Tables\Actions\Action::make('downloadXml')
                    ->label('XML')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->url(fn (Despatch $record): ?string => $record->enlace_del_xml)
                    ->openUrlInNewTab()
                    ->visible(fn (Despatch $record): bool => !empty($record->enlace_del_xml)),

                Tables\Actions\Action::make('downloadCdr')
                    ->label('CDR')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->url(fn (Despatch $record): ?string => $record->enlace_del_cdr)
                    ->openUrlInNewTab()
                    ->visible(fn (Despatch $record): bool => !empty($record->enlace_del_cdr)),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Despatch $record): bool => !$record->accepted_by_sunat)
                    ->requiresConfirmation()
                    ->modalHeading('Eliminar Guía de Remisión')
                    ->modalDescription(fn (Despatch $record): string =>
                        "¿Está seguro de eliminar la guía {$record->series}-{$record->number}? Esta acción no se puede deshacer."
                    )
                    ->modalSubmitActionLabel('Sí, eliminar')
                    ->successNotificationTitle('Guía eliminada')
                    ->before(function (Despatch $record) {
                        // Verificación adicional de seguridad
                        if ($record->accepted_by_sunat) {
                            throw new \Exception('No se puede eliminar una guía aceptada por SUNAT');
                        }
                    }),

            ])
            ->headerActions([
                TableAction::make('importFromXml')
                    ->label('Importar XML')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('info')
                    ->form([
                        FileUpload::make('xml_files')
                            ->label('Archivos XML de SUNAT')
                            ->acceptedFileTypes(['application/xml', 'text/xml'])
                            ->multiple()
                            ->maxFiles(20)
                            ->maxSize(2048)
                            ->required()
                            ->storeFiles(false),
                    ])
                    ->action(function (array $data) {
                        try {
                            $uploadedFiles = $data['xml_files'];

                            if (!is_array($uploadedFiles)) {
                                $uploadedFiles = [$uploadedFiles];
                            }

                            $xmlContents = [];

                            foreach ($uploadedFiles as $uploadedFile) {
                                if (is_object($uploadedFile) && method_exists($uploadedFile, 'get')) {
                                    $xmlContents[] = $uploadedFile->get();
                                } else {
                                    $tempPath = storage_path('app/livewire-tmp/' . $uploadedFile);
                                    if (file_exists($tempPath)) {
                                        $xmlContents[] = file_get_contents($tempPath);
                                        unlink($tempPath);
                                    }
                                }
                            }

                            if (empty($xmlContents)) {
                                throw new Exception('No se pudieron leer los archivos XML');
                            }

                            $importService = app(SunatXmlImportService::class);

                            // Usar importación masiva o individual según la cantidad
                            if (count($xmlContents) === 1) {
                                $result = $importService->importFromXml($xmlContents[0]);

                                if ($result['success']) {
                                    $createdEntitiesText = '';
                                    if (!empty($result['created_entities'])) {
                                        $createdEntitiesText = "\n\nEntidades creadas:\n• " . implode("\n• ", $result['created_entities']);
                                    }

                                    Notification::make()
                                        ->title('Importación Exitosa')
                                        ->body("Guía {$result['despatch']->series}-{$result['despatch']->number} importada correctamente.{$createdEntitiesText}")
                                        ->success()
                                        ->persistent()
                                        ->send();
                                } else {
                                    Notification::make()
                                        ->title('Error en la Importación')
                                        ->body($result['error'])
                                        ->danger()
                                        ->persistent()
                                        ->send();
                                }
                            } else {
                                // Importación masiva
                                $result = $importService->importMultipleFromXml($xmlContents);

                                $statusSummary = "✅ Importadas: {$result['imported']}\n❌ Fallidas: {$result['failed']}\n📁 Total: {$result['total']}";

                                $detailsText = '';
                                foreach ($result['details'] as $detail) {
                                    if ($detail['status'] === 'success') {
                                        $detailsText .= "\n✓ {$detail['serie_numero']}";
                                    } else {
                                        $detailsText .= "\n✗ {$detail['message']}";
                                    }
                                }

                                $createdEntitiesText = '';
                                if (!empty($result['created_entities'])) {
                                    $createdEntitiesText = "\n\n📝 Entidades nuevas:\n• " . implode("\n• ", array_slice($result['created_entities'], 0, 10));
                                    if (count($result['created_entities']) > 10) {
                                        $createdEntitiesText .= "\n... y " . (count($result['created_entities']) - 10) . " más";
                                    }
                                }

                                Notification::make()
                                    ->title('Importación Masiva Completada')
                                    ->body("{$statusSummary}{$detailsText}{$createdEntitiesText}")
                                    ->success($result['imported'] > 0)
                                    ->warning($result['failed'] > 0 && $result['imported'] === 0)
                                    ->persistent()
                                    ->send();
                            }

                            return redirect()->route('filament.admin.resources.despatches.index');

                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Error Procesando XMLs')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    })
                    ->modalHeading('Importar Guías desde XML de SUNAT')
                    ->modalDescription('Puedes importar una o múltiples guías a la vez (máximo 20 archivos)')
                    ->modalSubmitActionLabel('Importar')
                    ->modalWidth('md')
            ])
            ->bulkActions([

            ])
            ->defaultSort('created_at', 'desc');
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
    private static function getErrorMessage(string $error): string
    {
        if (strpos($error, 'timeout') !== false ||
            strpos($error, '15 seconds') !== false) {
            return 'Los servidores de SUNAT están temporalmente lentos. Intente nuevamente en unos minutos.';
        }

        if (strpos($error, 'file_get_contents') !== false ||
            strpos($error, 'HTTP request failed') !== false ||
            strpos($error, 'Failed to open stream') !== false) {
            return 'Los servidores de SUNAT están temporalmente ocupados. Intente nuevamente.';
        }

        return 'Error temporal descargando desde SUNAT. Intente nuevamente.';
    }
    private static function calculateTravelAllowances(callable $get, $record = null): float
    {
        $driverId = $get('driver_id');
        $emissionDate = $get('emission_date');

        // Si no hay conductor o fecha, no hay viáticos
        if (!$driverId || !$emissionDate) {
            return 0.00;
        }

        // Configuración: monto de viáticos por día
        $dailyTravelAllowance = 30.00;

        // Formatear la fecha para comparación
        $emissionDateFormatted = is_string($emissionDate)
            ? $emissionDate
            : (is_object($emissionDate) ? $emissionDate->format('Y-m-d') : $emissionDate);

        // Contar cuántas guías tiene este conductor en la misma fecha (excluyendo la actual si existe)
        $existingGuidesCount = \App\Models\Despatch::where('driver_id', $driverId)
            ->whereDate('emission_date', $emissionDateFormatted)
            ->where('accepted_by_sunat', true)
            ->when($record, function ($query) use ($record) {
                // Si es una edición, excluir la guía actual del conteo
                return $query->where('id', '!=', $record->id);
            })
            ->count();

        // Si es la primera guía del día (no hay guías existentes), asignar viáticos
        return $existingGuidesCount === 0 ? $dailyTravelAllowance : 0.00;
    }
    private static function autoCompleteFromFourFields(callable $get, callable $set): void
    {
        $loadingPoint = $get('loading_point');
        $departureLocation = $get('departure_location');
        $arrivalLocation = $get('arrival_location');
        $unloadingPoint = $get('unloading_point');

        // Solo buscar si tenemos los 4 campos completos
        if ($loadingPoint && $departureLocation && $arrivalLocation && $unloadingPoint) {
            $config = OperationalExpenseConfig::where('departure_point', $loadingPoint)
                                            ->where('departure_location', $departureLocation)
                                            ->where('arrival_location', $arrivalLocation)
                                            ->where('destination_point', $unloadingPoint)
                                            ->first();

            if ($config) {
                $set('tolls', $config->tolls);
                $set('loading_expenses', $config->loading_expenses);
                $set('variable_salary', $config->variable_salary);
                $set('operations_manager', $config->operations_manager);
                $set('security', $config->security);

                // Recalcular viáticos
                $calculatedValue = static::calculateTravelAllowances($get, null);
                $set('travel_allowances', $calculatedValue);
            } else {
                // Si no hay configuración, poner todo en 0 (excepto viáticos que se calculan automáticamente)
                $set('tolls', 0);
                $set('loading_expenses', 0);
                $set('variable_salary', 0);
                $set('operations_manager', 0);
                $set('security', 0);

                // Recalcular viáticos
                $calculatedValue = static::calculateTravelAllowances($get, null);
                $set('travel_allowances', $calculatedValue);
            }
        }
    }
}
