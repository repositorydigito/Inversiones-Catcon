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
                Section::make('Datos del Remitente')
                    ->columns(4)
                    ->schema([
                        Select::make('company_id')
                            ->label('Razón Social')
                            ->options(Company::all()->pluck('name', 'id'))
                            ->required()
                            ->default(Company::first()?->id),
                        TextInput::make('company_ruc')
                            ->label('RUC')
                            ->default(fn () => Company::first()?->ruc)
                            ->required(),
                        TextInput::make('departure_address')
                            ->label('Punto de Partida')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('departure_sunat_establishment_code')
                            ->label('Código de Establecimiento (Partida)')
                            ->maxLength(4)
                            ->default('0000')
                            ->required(),
                        /* TextInput::make('loading_point')
                            ->label('Punto 1')
                            ->maxLength(255), */                        
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
                Section::make('Datos del Destinatario')
                    ->columns(4)
                    ->schema([
                        Select::make('client_id')
                            ->label('Razón Social')
                            ->options(Client::all()->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->afterStateUpdated(function ($state, $set) {
                                $client = \App\Models\Client::find($state);
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
                            ->readOnly(),
                        TextInput::make('arrival_address')
                            ->label('Punto de Llegada')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('arrival_sunat_establishment_code')
                            ->label('Código de Establecimiento (Llegada)')
                            ->maxLength(4)
                            ->default('0000')
                            ->required(),
                        /* TextInput::make('unloading_point')
                            ->label('Punto 4')
                            ->maxLength(255), */                        
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
                            ->default('VVV1'),

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
                            ->options(OperationalExpenseConfig::distinct()->pluck('departure_point', 'departure_point'))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $get, callable $set) {
                                static::autoCompleteFromFourFields($get, $set);
                            }),

                        Select::make('departure_location')
                            ->label('Punto de Partida')
                            ->options(OperationalExpenseConfig::distinct()->pluck('departure_location', 'departure_location'))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $get, callable $set) {
                                static::autoCompleteFromFourFields($get, $set);
                            }),
                        
                        Select::make('arrival_location')
                            ->label('Punto de Llegada')
                            ->options(OperationalExpenseConfig::distinct()->pluck('arrival_location', 'arrival_location'))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $get, callable $set) {
                                static::autoCompleteFromFourFields($get, $set);
                            }),

                        Select::make('unloading_point')
                            ->label('Punto 4')
                            ->options(OperationalExpenseConfig::distinct()->pluck('destination_point', 'destination_point'))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $get, callable $set) {
                                static::autoCompleteFromFourFields($get, $set);
                            }),                        
                    ])
                    ->columns(4),

                Section::make('Gastos Operativos')
                    ->description('Información autogenerada en función a los puntos de ruta para el control de gastos operativos.')
                    ->schema([
                        Forms\Components\Group::make()
                            ->schema([
                                Forms\Components\TextInput::make('product')
                                    ->label('Producto')
                                    ->maxLength(255),
                                /* Forms\Components\TextInput::make('supplier')
                                    ->label('Proveedor')
                                    ->maxLength(255), */
                                Forms\Components\TextInput::make('tolls')
                                    ->label('Peajes')
                                    ->numeric()
                                    ->prefix('S/.')
                                    ->step(0.01)
                                    ->default(0)
                                    ->disabled()  
                                    ->dehydrated() 
                                    ->live(),
                                Forms\Components\TextInput::make('loading_expenses')
                                    ->label('Gastos de Carga')
                                    ->numeric()
                                    ->prefix('S/.')
                                    ->step(0.01)
                                    ->default(0)
                                    ->disabled()  
                                    ->dehydrated() 
                                    ->live(),
                            ])
                            ->columns(4),

                        Forms\Components\Group::make()
                            ->schema([                               
                                Forms\Components\TextInput::make('travel_allowances')
                                    ->label('Viáticos')
                                    ->numeric()
                                    ->prefix('S/.')
                                    ->step(0.01)
                                    ->disabled() 
                                    ->dehydrated() 
                                    ->live() 
                                    ->afterStateHydrated(function (Forms\Components\TextInput $component, $state, $record, callable $get) {
                                        // Calcular valor inicial cuando se carga el formulario
                                        $calculatedValue = static::calculateTravelAllowances($get, $record);
                                        $component->state($calculatedValue);
                                    }),

                                Forms\Components\TextInput::make('variable_salary')
                                    ->label('Sueldo Variable')
                                    ->numeric()
                                    ->prefix('S/.')
                                    ->step(0.01)
                                    ->default(0)
                                    ->disabled()  
                                    ->dehydrated() 
                                    ->live(),

                                Forms\Components\TextInput::make('operations_manager')
                                    ->label('Jefe de Operaciones')
                                    ->numeric()
                                    ->prefix('S/.')
                                    ->step(0.01)
                                    ->default(0)
                                    ->disabled() 
                                    ->dehydrated() 
                                    ->live(),

                                Forms\Components\TextInput::make('security')
                                    ->label('Seguridad')
                                    ->numeric()
                                    ->prefix('S/.')
                                    ->step(0.01)
                                    ->default(0)
                                    ->disabled()  
                                    ->dehydrated() 
                                    ->live(),
                            ])
                            ->columns(4),
                    ]),

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
                            ->default('')
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
                                    ->required()
                                    ->minValue(0.01)
                                    ->step(0.01),
                                Select::make('unit_of_measure_id')
                                ->label('Unidad de Medida')
                                ->options(MeasureUnit::all()->pluck('description', 'id'))
                                ->searchable()
                                ->required(),
                            ])
                            ->columns(6)
                            ->defaultItems(1)
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
                                    if ($mainDriverId && $vehicle->driver->id == $mainDriverId) {
                                        $errorMessages[] = "🚛 {$vehicle->plate_number}: Su conductor ya es el conductor principal";
                                        continue;
                                    }

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
                Tables\Actions\Action::make('consultDespatchStatus')
                    ->label('SUNAT')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->action(function (Despatch $record, DespatchService $nubefactService) {
                        try {
                            $response = $nubefactService->consultDespatchStatus($record); // Llama al nuevo método consultDespatchStatus

                            Notification::make()
                                ->title('Consulta de Estado Exitosa')
                                ->body("Estado de la guía #{$record->series}-{$record->number}: " . ($record->accepted_by_sunat ? 'ACEPTADA' : 'RECHAZADA / PENDIENTE'))
                                ->success()
                                ->send();

                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Error al Consultar Estado')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }

                        $record->refresh(); // ¡MUY IMPORTANTE! Recarga el modelo para que la tabla muestre el nuevo estado y URLs
                    }),
                    // Muestra si la guía fue generada (tiene un response code, o al menos no ha sido aceptada)
                    // y no ha sido aceptada por SUNAT (para seguir consultando hasta que se acepte o rechace).
                    //->visible(fn (Despatch $record): bool => !is_null($record->sunat_response_code) && !$record->accepted_by_sunat), // Ajusta la visibilidad según tu flujo exacto.

                // Opcional: Acción para descargar PDF/XML/CDR si existen
                Tables\Actions\Action::make('downloadPdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->url(fn (Despatch $record): string => $record->enlace_del_pdf ?? '#')
                    ->openUrlInNewTab()
                    ->visible(fn (Despatch $record): bool => !is_null($record->enlace_del_pdf)),

                Tables\Actions\Action::make('downloadXml')
                    ->label('XML')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->url(fn (Despatch $record): string => $record->enlace_del_xml ?? '#')
                    ->openUrlInNewTab()
                    ->visible(fn (Despatch $record): bool => !is_null($record->enlace_del_xml)),

                Tables\Actions\Action::make('downloadCdr')
                    ->label('CDR')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->url(fn (Despatch $record): string => $record->enlace_del_cdr ?? '#')
                    ->openUrlInNewTab()
                    ->visible(fn (Despatch $record): bool => !is_null($record->enlace_del_cdr)),

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
            // 'edit' => Pages\EditDespatch::route('/{record}/edit'),
        ];
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
