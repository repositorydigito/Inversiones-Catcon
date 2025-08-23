<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InvoiceResource\Pages;
use App\Filament\Resources\InvoiceResource\RelationManagers;
use App\Models\Invoice;
use App\Models\Client;
use App\Models\MeasureUnit;
use App\Models\Despatch;
use App\Models\Service;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Actions;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker as FilterDatePicker;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationLabel = 'Facturas';
    protected static ?string $pluralModelLabel = 'Facturas';
    protected static ?string $modelLabel = 'Factura';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Seleccionar Guías de Remisión')
                    ->columns(1)
                    ->schema([
                        Select::make('selected_despatches')
                            ->label('Guías de Remisión Disponibles')
                            ->multiple()
                            ->options(function () {
                                return Despatch::with('client')
                                    ->where('accepted_by_sunat', true)
                                    ->get()
                                    ->mapWithKeys(function ($despatch) {
                                        $itemsCount = $despatch->items->count();
                                        $totalQty = $despatch->items->sum('quantity');
                                        return [
                                            $despatch->id => "GR {$despatch->series}-{$despatch->number}"
                                        ];
                                    });
                            })
                            ->searchable()
                            ->preload()
                            ->live()                            
                            ->helperText('Selecciona una o más guías de remisión.')
                            ->columnSpanFull(),                       
                    ])
                    ->visible(fn (string $operation): bool => $operation === 'create'),               

                Section::make('Datos de la Factura')
                    ->description('Información general del comprobante.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('series')
                            ->readOnly()
                            ->maxLength(4)
                            ->helperText('por defecto F001')
                            ->default(function () {
                                return 'F001';
                            })
                            ->label('Serie')
                            ->live()
                            ->disabled(fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated()
                            ->afterStateHydrated(function ($state, $set) {
                                if ($state) {

                                    $nextNumber = static::getNextCorrelativeNumber($state);
                                    $set('number', $nextNumber);
                                }
                            })
                            ->afterStateUpdated(function ($state, $set, $get) {
                                if ($state) {
                                    $nextNumber = static::getNextCorrelativeNumber($state);
                                    $set('number', $nextNumber);
                                }
                            })
                            ->columnSpan(1),
                        TextInput::make('number')
                            ->readOnly()
                            ->label('Número')
                            ->disabled(fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated()
                            ->helperText('Se genera automáticamente según la serie')
                            ->columnSpan(1),
                        Select::make('invoice_type')
                            ->label('Tipo de Comprobante')
                            ->options([
                                '01' => 'Factura',
                                '07' => 'Nota de Crédito',
                                '08' => 'Nota de Débito',
                            ])
                            ->required()
                            ->default('01')
                            ->columnSpan(1),
                        Select::make('transaction_type')
                            ->label('Tipo de Transacción SUNAT')
                            ->options([
                                '0101' => '0101 - Venta Interna',
                                '0102' => '0102 - Exportación',
                                '0103' => '0103 - No Domiciliados',
                                '0104' => '0104 - Venta Interna – Anticipos',
                                '0105' => '0105 - Venta Itinerante',
                                '0106' => '0106 - Factura Guía',
                                '0107' => '0107 - Venta Arroz Pilado',
                                '0108' => '0108 - Factura - Comprobante de Percepción',
                                '0110' => '0110 - Factura - Guía remitente',
                                '0111' => '0111 - Factura - Guía transportista',
                            ])
                            ->required()
                            ->default('0101')
                            ->columnSpan(1),
                        DatePicker::make('emission_date')
                            ->label('Fecha de Emisión')
                            ->required()
                            ->default(now())
                            ->native(false)
                            ->columnSpan(1),
                        DatePicker::make('due_date')
                            ->label('Fecha de Vencimiento')
                            ->nullable()
                            ->native(false)
                            ->columnSpan(1),
                        Select::make('currency')
                            ->label('Moneda')
                            ->options([
                                '1' => 'SOLES (PEN)',
                                '2' => 'DÓLARES (USD)',
                            ])
                            ->required()
                            ->default('1')
                            ->columnSpan(1),
                        TextInput::make('igv_percentage')
                            ->label('Porcentaje de IGV (%)')
                            ->required()
                            ->numeric()
                            ->step(0.01)
                            ->default(18.00)
                            ->live()
                            ->afterStateUpdated(function ($state, $set, $get) {
                                $items = $get('items') ?? [];
                                foreach ($items as $index => $item) {
                                    $quantity = (float) ($item['quantity'] ?? 0);
                                    $unitValue = (float) ($item['unit_value'] ?? 0);
                                    $discount = (float) ($item['discount'] ?? 0);
                                    $igvType = $item['igv_type'] ?? '1';
                                    $igvPercentage = (float) ($state ?? 18.00);

                                    $subtotal = ($quantity * $unitValue) - $discount;
                                    $igv = 0;
                                    $unitPrice = 0;
                                    
                                    if ($igvType === '1' && $igvPercentage > 0) {
                                        $igv = $subtotal * ($igvPercentage / 100);
                                        $unitPrice = $unitValue * (1 + ($igvPercentage / 100));
                                    } else {
                                        $unitPrice = $unitValue;
                                    }
                                    
                                    $total = $subtotal + $igv;

                                    $set("items.{$index}.subtotal", round($subtotal, 2));
                                    $set("items.{$index}.igv", round($igv, 2));
                                    $set("items.{$index}.total", round($total, 2));
                                    $set("items.{$index}.unit_price", round($unitPrice, 2));
                                }
                            })
                            ->columnSpan(1),
                        TextInput::make('global_discount')
                            ->label('Descuento Global')
                            ->numeric()
                            ->step(0.01)
                            ->default(0.00)
                            ->columnSpan(1),
                        Toggle::make('detraction')
                            ->label('¿Aplica Detracción?')
                            ->default(false)
                            ->columnSpan(1),
                        TextInput::make('observations')
                            ->label('Observaciones')
                            ->maxLength(255)
                            ->nullable()
                            ->columnSpanFull(),
                    ]),

                Section::make('Datos del Cliente')
                    ->description('Información del cliente (se llena automáticamente desde las guías).')
                    ->columns(2)
                    ->schema([
                        Select::make('client_id')
                            ->label('Seleccionar Cliente')
                            ->options(Client::all()->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->afterStateUpdated(function ($state, $set) {
                                $client = Client::find($state);
                                if ($client) {
                                    $set('client_document_type', $client->document_type);
                                    $set('client_document_number', $client->document_number);
                                    $set('client_name', $client->name);
                                    $set('client_address', $client->address);
                                    $set('client_email', $client->email);
                                } else {
                                    $set('client_document_type', null);
                                    $set('client_document_number', null);
                                    $set('client_name', null);
                                    $set('client_address', null);
                                    $set('client_email', null);
                                }
                            })
                            ->live()
                            ->disabled(fn ($get) => !empty($get('selected_despatches')) && !$get('manual_mode'))
                            ->columnSpanFull(),


                        TextInput::make('client_document_type')
                            ->label('Tipo Doc. Cliente (SUNAT)')
                            ->readOnly()
                            ->required()
                            ->disabled(fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated(fn ($state) => filled($state)),
                        TextInput::make('client_document_number')
                            ->label('Número Doc. Cliente (SUNAT)')
                            ->readOnly()
                            ->required()
                            ->disabled(fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated(fn ($state) => filled($state)),
                        TextInput::make('client_name')
                            ->label('Denominación Cliente (SUNAT)')
                            ->readOnly()
                            ->required()
                            ->disabled(fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated(fn ($state) => filled($state)),
                        TextInput::make('client_address')
                            ->label('Dirección Cliente (SUNAT)')
                            ->readOnly()
                            ->nullable()
                            ->disabled(fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated(fn ($state) => filled($state)),
                        TextInput::make('client_email')
                            ->label('Email Cliente (SUNAT)')
                            ->readOnly()
                            ->email()
                            ->nullable()
                            ->disabled(fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated(fn ($state) => filled($state)),
                    ]),

                Section::make('Detalles de Ítems')
                    ->description('Productos/servicios (se cargan automáticamente desde las guías).')
                    ->schema([
                        Repeater::make('items')
                            ->label('')
                            ->schema([
                                Select::make('service_id')
                                    ->label('Seleccionar Servicio/Producto')
                                    ->options(\App\Models\Service::active()->orderBy('code')->get()->pluck('full_name', 'id'))
                                    ->searchable()
                                    ->preload()
                                    ->nullable()
                                    ->placeholder('Buscar servicio...')
                                    ->live()
                                    ->afterStateUpdated(function ($state, $set) {
                                        if ($state) {
                                            $service = \App\Models\Service::find($state);
                                            if ($service) {
                                                $set('code', $service->code);
                                                $set('description', $service->name);
                
                                                $defaultUnit = \App\Models\MeasureUnit::where('code', 'ZZ')->first();
                                                if ($defaultUnit) {
                                                    $set('unit_of_measure_id', $defaultUnit->id);
                                                }
                                            }
                                        }
                                    })
                                    ->columnSpan(3),
                                Select::make('unit_of_measure_id')
                                    ->label('Unidad de Medida')
                                    ->options(MeasureUnit::all()->pluck('description', 'id'))
                                    ->required()
                                    ->columnSpan(1),
                                TextInput::make('code')
                                    ->label('Código')
                                    ->maxLength(50)
                                    ->nullable()
                                    ->columnSpan(1),
                                TextInput::make('description')
                                    ->label('Descripción')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(1),
                                TextInput::make('quantity')
                                    ->label('Cantidad')
                                    ->required()
                                    ->numeric()
                                    ->step(0.01)
                                    ->default(1)
                                    ->lazy()
                                    ->afterStateUpdated(function ($state, $set, $get) {
                                        static::calculateItemTotals($set, $get);
                                    })
                                    ->columnSpan(1),
                                TextInput::make('unit_value')
                                    ->label('Valor Unitario (sin IGV)')
                                    ->required()
                                    ->numeric()
                                    ->step(0.01)
                                    ->lazy()
                                    ->afterStateUpdated(function ($state, $set, $get) {
                                        static::calculateItemTotals($set, $get);
                                    })
                                    ->columnSpan(1),
                                TextInput::make('unit_price')
                                    ->label('Precio Unitario (con IGV)')
                                    ->required()
                                    ->numeric()
                                    ->step(0.01)
                                    ->lazy()
                                    ->afterStateUpdated(function ($state, $set, $get) {
                                        static::calculateItemTotals($set, $get);
                                    })
                                    ->columnSpan(1),
                                TextInput::make('discount')
                                    ->label('Descuento por Ítem')
                                    ->numeric()
                                    ->step(0.01)
                                    ->default(0.00)
                                    ->lazy()
                                    ->afterStateUpdated(function ($state, $set, $get) {
                                        static::calculateItemTotals($set, $get);
                                    })
                                    ->columnSpan(1),
                                Select::make('igv_type')
                                    ->label('Tipo de IGV')
                                    ->options([
                                        '1' => 'Gravado',
                                        '8' => 'Exonerado',
                                        '9' => 'Inafecto',
                                    ])
                                    ->required()
                                    ->default('1')
                                    ->live()
                                    ->afterStateUpdated(function ($state, $set, $get) {
                                        static::calculateItemTotals($set, $get);
                                    })
                                    ->columnSpan(1),

                                TextInput::make('subtotal')
                                    ->label('Subtotal')
                                    ->numeric()
                                    ->readOnly()
                                    ->dehydrated()
                                    ->columnSpan(1),
                                TextInput::make('igv')
                                    ->label('IGV')
                                    ->numeric()
                                    ->readOnly()
                                    ->dehydrated()
                                    ->columnSpan(1),
                                TextInput::make('total')
                                    ->label('Total')
                                    ->numeric()
                                    ->readOnly()
                                    ->dehydrated()
                                    ->columnSpan(1),
                            ])
                            ->columns(6)
                            ->defaultItems(1)
                            ->minItems(1)
                            ->reorderable()
                            ->collapsible()
                            ->cloneable()
                            ->addActionLabel('Añadir Nuevo Ítem')
                            ->itemLabel(fn (array $state): ?string => 
                                $state['description'] ?? 'Nuevo ítem'
                            )
                            ->columnSpanFull(),
                    ]),

                Section::make('Totales de la Factura')
                    ->description('Resumen de los montos calculados.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('total_taxable')
                            ->label('Total Gravada')
                            ->numeric()
                            ->readOnly()
                            ->default(0.00)
                            ->dehydrated(),
                        TextInput::make('total_unaffected')
                            ->label('Total Inafecta')
                            ->numeric()
                            ->readOnly()
                            ->default(0.00)
                            ->dehydrated(),
                        TextInput::make('total_exonerated')
                            ->label('Total Exonerada')
                            ->numeric()
                            ->readOnly()
                            ->default(0.00)
                            ->dehydrated(),
                        TextInput::make('total_igv')
                            ->label('Total IGV')
                            ->numeric()
                            ->readOnly()
                            ->default(0.00)
                            ->dehydrated(),
                        TextInput::make('total_discount')
                            ->label('Total Descuento')
                            ->numeric()
                            ->readOnly()
                            ->default(0.00)
                            ->dehydrated(),
                        TextInput::make('total')
                            ->label('TOTAL GENERAL')
                            ->numeric()
                            ->readOnly()
                            ->default(0.00)
                            ->dehydrated()
                            ->live()
                            ->afterStateUpdated(function ($state, $set, $get) {
                                $newTotal = (float) ($state ?? 0);
                                $numberOfInstallments = (int) ($get('number_of_installments') ?? 1);
                                $emissionDate = $get('emission_date');
                                $isCreditPayment = $get('is_credit_payment');
                                $existingInstallments = $get('installments') ?? [];
                                

                                if ($isCreditPayment && $newTotal > 0 && $emissionDate && !empty($existingInstallments)) {
                                    // Calcular montos en partes iguales
                                    $amountPerInstallment = round($newTotal / $numberOfInstallments, 2);
                                    $lastInstallmentAmount = $newTotal - ($amountPerInstallment * ($numberOfInstallments - 1));
                                    

                                    $updatedInstallments = [];
                                    for ($i = 0; $i < $numberOfInstallments && $i < count($existingInstallments); $i++) {
                                        $amount = ($i === $numberOfInstallments - 1) ? $lastInstallmentAmount : $amountPerInstallment;
                                        
                                        $updatedInstallments[] = [
                                            'installment_number' => $existingInstallments[$i]['installment_number'] ?? 'Cuota' . str_pad($i + 1, 3, '0', STR_PAD_LEFT),
                                            'amount' => $amount,
                                            'due_date' => $existingInstallments[$i]['due_date'] ?? null,
                                            'order' => $existingInstallments[$i]['order'] ?? ($i + 1),
                                        ];
                                    }
                                    
                                    $set('installments', $updatedInstallments);
                                }
                            }),
                    ]),


                Section::make('Configuración de Pago')
                    ->description('Define si es pago contado o crédito, y configuración de detracción.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_credit_payment')
                            ->label('¿Pago a Crédito?')
                            ->default(false)
                            ->live()
                            ->helperText('Activa para configurar fecha de vencimiento')
                            ->columnSpan(1),

                        DatePicker::make('due_date')
                            ->label('Fecha de Vencimiento')
                            ->native(false)
                            ->afterOrEqual('emission_date')
                            ->visible(fn ($get) => $get('is_credit_payment'))
                            ->required(fn ($get) => $get('is_credit_payment'))
                            ->helperText('Para calcular días de crédito')
                            ->columnSpan(1),

                        Toggle::make('detraction')
                            ->label('¿Aplica Detracción?')
                            ->default(true)
                            ->live()
                            ->helperText('Servicios de transporte están sujetos a detracción del 4%')
                            ->columnSpan(1),

                        TextInput::make('detraction_percentage')
                            ->label('% Detracción')
                            ->numeric()
                            ->step(0.01)
                            ->default(4.00)
                            ->suffix('%')
                            ->visible(fn ($get) => $get('detraction'))
                            ->required(fn ($get) => $get('detraction'))
                            ->columnSpan(1),

                        // Información calculada de pago
                        Placeholder::make('payment_summary')
                            ->label('Resumen de Pago')
                            ->content(function ($get) {
                                $total = (float) ($get('total') ?? 0);
                                $dueDate = $get('due_date');
                                $isCredit = $get('is_credit_payment');
                                $hasDetraction = $get('detraction');
                                $detractionPercentage = (float) ($get('detraction_percentage') ?? 4.00);
                                
                                if ($total <= 0) {
                                    return 'Complete los items para ver el resumen de pago.';
                                }

                                $content = '';
                                
                                // Tipo de pago
                                if ($isCredit && $dueDate) {
                                    $daysCredit = now()->diffInDays(\Carbon\Carbon::parse($dueDate), false);
                                    $content .= "**Pago:** CRÉDITO a {$daysCredit} días\n";
                                    $content .= "**Vence:** " . \Carbon\Carbon::parse($dueDate)->format('d/m/Y') . "\n\n";
                                } else {
                                    $content .= "**Pago:** CONTADO\n\n";
                                }
                                
                                // Información financiera
                                $content .= "**Información Financiera:**\n";
                                $content .= "• Total factura: S/ " . number_format($total, 2) . "\n";
                                
                                if ($hasDetraction && $total > 0) {
                                    $detractionAmount = $total * ($detractionPercentage / 100);
                                    $netPayable = $total - $detractionAmount;
                                    $content .= "• Detracción ({$detractionPercentage}%): S/ " . number_format($detractionAmount, 2) . "\n";
                                    $content .= "• **Monto neto a pagar: S/ " . number_format($netPayable, 2) . "**\n";
                                } else {
                                    $content .= "• **Monto a pagar: S/ " . number_format($total, 2) . "**\n";
                                }
                                
                                return $content;
                            })
                            ->columnSpanFull(),
                    ]),


                Section::make('Información de Detracción')
                    ->description('Configuración específica para el sistema de detracciones de SUNAT.')
                    ->visible(fn ($get) => $get('detraction'))
                    ->columns(2)
                    ->schema([
                        Select::make('detraction_service_code')
                            ->label('Código de Bien/Servicio')
                            ->options([
                                '027' => '027 - Servicio de transporte de carga',
                                '001' => '001 - Azúcar',
                                '003' => '003 - Alcohol Etílico',
                                '004' => '004 - Recursos Hidrobiológicos',
                                '005' => '005 - Maíz amarillo duro',
                                '006' => '006 - Algodón',
                                '007' => '007 - Caña de azúcar',
                                '008' => '008 - Madera',
                                '009' => '009 - Arena y piedra',
                            ])
                            ->default('027')
                            ->required()
                            ->columnSpan(1),

                        Select::make('detraction_payment_method')
                            ->label('Medio de Pago')
                            ->options([
                                '001' => '001 - Depósito en cuenta',
                                '002' => '002 - Giro',
                                '003' => '003 - Transferencia',
                            ])
                            ->default('001')
                            ->required()
                            ->columnSpan(1),

                        TextInput::make('detraction_bank_account')
                            ->label('Nro. Cuenta Banco de la Nación')
                            ->helperText('Cuenta de detracciones del proveedor')
                            ->maxLength(15)
                            ->columnSpan(1),

                        Placeholder::make('detraction_info')
                            ->label(' Información de Detracción')
                            ->content(function ($get) {
                                $total = (float) ($get('total') ?? 0);
                                $percentage = (float) ($get('detraction_percentage') ?? 4.00);
                                $serviceCode = $get('detraction_service_code') ?? '027';
                                $paymentMethod = $get('detraction_payment_method') ?? '001';
                                $bankAccount = $get('detraction_bank_account') ?? '';
                                
                                if ($total <= 0) {
                                    return '⏳ Complete los montos para calcular la detracción.';
                                }
                                
                                $detractionAmount = $total * ($percentage / 100);
                                
                                $content = "** Cálculo de Detracción:**\n";
                                $content .= "• Código: {$serviceCode}\n";
                                $content .= "• Porcentaje: {$percentage}%\n";
                                $content .= "• Base imponible: S/ " . number_format($total, 2) . "\n";
                                $content .= "• **Monto detracción: S/ " . number_format($detractionAmount, 2) . "**\n";
                                
                                if ($bankAccount) {
                                    $content .= "• Cuenta BN: {$bankAccount}\n";
                                }
                                
                                return $content;
                            })
                            ->columnSpan(1),
                    ]),


                Section::make('Configuración de Cuotas')
                    ->description('Configure las cuotas de pago para facturas a crédito.')
                    ->visible(fn ($get) => $get('is_credit_payment'))
                    ->columns(1)
                    ->schema([

                        Select::make('number_of_installments')
                            ->label('Número de Cuotas')
                            ->options([
                                1 => '1 cuota (pago único)',
                                2 => '2 cuotas',
                                3 => '3 cuotas',
                                4 => '4 cuotas',
                                6 => '6 cuotas',
                                12 => '12 cuotas',
                            ])
                            ->default(1)
                            ->live()
                            ->afterStateUpdated(function ($state, $set, $get) {
                                $numberOfInstallments = (int) $state;
                                $total = (float) ($get('total') ?? 0);
                                $emissionDate = $get('emission_date');
                                
                                if ($total <= 0 || !$emissionDate) {
                                    return;
                                }
                                
                                // Calcular montos en partes iguales
                                $amountPerInstallment = round($total / $numberOfInstallments, 2);
                                $lastInstallmentAmount = $total - ($amountPerInstallment * ($numberOfInstallments - 1));
                                
                                // Generar cuotas con fechas válidas
                                $installments = [];
                                $baseDate = \Carbon\Carbon::parse($emissionDate);
                                
                                for ($i = 0; $i < $numberOfInstallments; $i++) {
                                    $amount = ($i === $numberOfInstallments - 1) ? $lastInstallmentAmount : $amountPerInstallment;
                                    
                                    // CRITICAL FIX: Asegurar fechas POSTERIORES a emission_date para evitar SUNAT 3267
                                    if ($numberOfInstallments === 1) {
                                        // Para pago único, usar +7 días (mínimo seguro)
                                        $daysToAdd = 7;
                                    } else {
                                        // Para múltiples cuotas, usar patrón +7, +14, +21, etc.
                                        $daysToAdd = ($i + 1) * 7;
                                    }
                                    
                                    $dueDate = $baseDate->copy()->addDays($daysToAdd);
                                    
                                    $installments[] = [
                                        'installment_number' => 'Cuota' . str_pad($i + 1, 3, '0', STR_PAD_LEFT),
                                        'amount' => $amount,
                                        'due_date' => $dueDate->format('Y-m-d'),
                                        'order' => $i + 1,
                                    ];
                                }
                                
                                $set('installments', $installments);
                                

                                $set('due_date', end($installments)['due_date']);
                            })
                            ->helperText('Al cambiar el número de cuotas se auto-calculará la división del monto total')
                            ->columnSpanFull(),
                            
                        // NUEVO: Botón para recalcular cuotas cuando el total haya cambiado
                        Actions::make([
                            Action::make('recalculate_installments')
                                ->label('Recalcular Cuotas con Total Actual')
                                ->icon('heroicon-o-calculator')
                                ->color('warning')
                                ->action(function ($set, $get) {
                                    $numberOfInstallments = (int) ($get('number_of_installments') ?? 1);
                                    $total = (float) ($get('total') ?? 0);
                                    $emissionDate = $get('emission_date');
                                    
                                    if ($total <= 0) {
                                        \Filament\Notifications\Notification::make()
                                            ->title('Error')
                                            ->body('No hay total para calcular. Primero configure los items de la factura.')
                                            ->danger()
                                            ->send();
                                        return;
                                    }
                                    
                                    if (!$emissionDate) {
                                        \Filament\Notifications\Notification::make()
                                            ->title('Error')
                                            ->body('Defina la fecha de emisión primero.')
                                            ->danger()
                                            ->send();
                                        return;
                                    }
                                    
                                    // Calcular montos en partes iguales
                                    $amountPerInstallment = round($total / $numberOfInstallments, 2);
                                    $lastInstallmentAmount = $total - ($amountPerInstallment * ($numberOfInstallments - 1));
                                    
                                    // Generar cuotas con fechas válidas
                                    $installments = [];
                                    $baseDate = \Carbon\Carbon::parse($emissionDate);
                                    
                                    for ($i = 0; $i < $numberOfInstallments; $i++) {
                                        $amount = ($i === $numberOfInstallments - 1) ? $lastInstallmentAmount : $amountPerInstallment;
                                        
                                        // Siguiendo patrón Greenter: +7 días por cuota
                                        if ($numberOfInstallments === 1) {
                                            $daysToAdd = 7; // Pago único a +7 días
                                        } else {
                                            $daysToAdd = ($i + 1) * 7; // Múltiples cuotas: +7, +14, +21, etc.
                                        }
                                        
                                        $dueDate = $baseDate->copy()->addDays($daysToAdd);
                                        
                                        $installments[] = [
                                            'installment_number' => 'Cuota' . str_pad($i + 1, 3, '0', STR_PAD_LEFT),
                                            'amount' => $amount,
                                            'due_date' => $dueDate->format('Y-m-d'),
                                            'order' => $i + 1,
                                        ];
                                    }
                                    
                                    $set('installments', $installments);
                                    $set('due_date', end($installments)['due_date']);
                                    
                                    \Filament\Notifications\Notification::make()
                                        ->title('Cuotas Recalculadas')
                                        ->body('Las cuotas han sido recalculadas con el total actual de S/ ' . number_format($total, 2))
                                        ->success()
                                        ->send();
                                })
                                ->visible(fn ($get) => (float) ($get('total') ?? 0) > 0)
                        ])->columnSpanFull(),
                            
                        Repeater::make('installments')
                            ->label('Cuotas de Pago')
                            // ->relationship('installments') // REMOVIDO: En CREATE no existe la relación aún
                            ->schema([
                                Hidden::make('installment_number'),
                                Hidden::make('order'),
                                
                                TextInput::make('amount')
                                    ->label('Monto')
                                    ->numeric()
                                    ->step(0.01)
                                    ->prefix('S/')
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, $set, $get) {
                                        // Auto-calcular el número de cuota y orden
                                        $installments = $get('../../installments') ?? [];
                                        $currentIndex = 0;
                                        foreach ($installments as $index => $installment) {
                                            if ($installment === $get('../')) {
                                                $currentIndex = $index;
                                                break;
                                            }
                                        }
                                        $cuotaNumber = str_pad($currentIndex + 1, 3, '0', STR_PAD_LEFT);
                                        $set('installment_number', 'Cuota' . $cuotaNumber);
                                        $set('order', $currentIndex + 1);
                                    })
                                    ->helperText('Monto editable - se calculó automáticamente pero puedes ajustarlo')
                                    ->columnSpan(1),
                                    
                                DatePicker::make('due_date')
                                    ->label('Fecha de Vencimiento')
                                    ->native(false)
                                    ->after('../../emission_date') // CRITICAL: Debe ser POSTERIOR a emission_date
                                    ->required()
                                    ->helperText('Debe ser posterior a la fecha de emisión para cumplir con SUNAT')
                                    ->columnSpan(1),
                            ])
                            ->columns(2)
                            ->defaultItems(1)
                            ->minItems(1)
                            ->maxItems(12)
                            ->reorderable(false)
                            ->addActionLabel('Agregar Cuota')
                            ->deleteAction(
                                fn (Action $action) => $action->requiresConfirmation()
                            )
                            ->itemLabel(fn (array $state): ?string => 
                                ($state['installment_number'] ?? 'Nueva cuota') . ': S/ ' . number_format($state['amount'] ?? 0, 2)
                            ),
                            
                        Placeholder::make('installments_summary')
                            ->label('Resumen de Cuotas')
                            ->content(function ($get) {
                                try {
                                    $installments = $get('installments') ?? [];
                                    $rawTotal = $get('total') ?? 0;
                                    
                                    // ENHANCED VALIDATION: Validación más robusta del total
                                    if (!is_numeric($rawTotal) || $rawTotal === '' || $rawTotal === null || is_array($rawTotal) || is_object($rawTotal)) {
                                        $invoiceTotal = 0.0;
                                    } else {
                                        $invoiceTotal = (float) $rawTotal;
                                    }
                                    
                                    if (empty($installments) || $invoiceTotal <= 0) {
                                        return '⏳ Configure las cuotas para ver el resumen.';
                                    }
                                    
                                    $totalCuotas = 0.0; // EXPLICIT FLOAT INITIALIZATION
                                    $content = "**📋 Cuotas configuradas:**\n";
                                    
                                    foreach ($installments as $index => $installment) {
                                        // ENHANCED VALIDATION: Validación más robusta del monto de cuota
                                        $rawAmount = $installment['amount'] ?? 0;
                                        $dueDate = $installment['due_date'] ?? '';
                                        
                                        // VALIDATION: Verificar que no sea array, object y que sea numérico válido
                                        if (!is_numeric($rawAmount) || $rawAmount === '' || $rawAmount === null || is_array($rawAmount) || is_object($rawAmount)) {
                                            $amount = 0.0;
                                        } else {
                                            $amount = (float) $rawAmount;
                                        }
                                        
                                        // SAFE ACCUMULATION: Asegurar que siempre se sume un float
                                        $totalCuotas = (float) $totalCuotas + (float) $amount;
                                        
                                        // FIX: Asegurar que $index sea entero antes de sumar
                                        $cuotaNum = (int) $index + 1;
                                        $content .= "• Cuota {$cuotaNum}: S/ " . number_format($amount, 2);
                                        
                                        if ($dueDate && !empty($dueDate)) {
                                            try {
                                                $parsedDate = \Carbon\Carbon::parse($dueDate);
                                                $content .= " (Vence: " . $parsedDate->format('d/m/Y') . ")";
                                                
                                                // Validar fecha
                                                $emissionDate = $get('emission_date');
                                                if ($emissionDate && $parsedDate->lte(\Carbon\Carbon::parse($emissionDate))) {
                                                    $content .= " ⚠️ **FECHA INVÁLIDA**";
                                                }
                                            } catch (\Exception $e) {
                                                $content .= " (Fecha inválida)";
                                            }
                                        }
                                        $content .= "\n";
                                    }
                                    
                                    // SAFE NUMBER FORMATTING: Asegurar que ambos valores sean float válidos
                                    $totalCuotas = (float) $totalCuotas;
                                    $invoiceTotal = (float) $invoiceTotal;
                                    
                                    $content .= "\n**💰 Total cuotas:** S/ " . number_format($totalCuotas, 2) . "\n";
                                    $content .= "**🧾 Total factura:** S/ " . number_format($invoiceTotal, 2) . "\n";
                                    
                                    // TRIPLE VALIDATION: Verificar que ambos valores sean numéricos válidos
                                    if (is_numeric($totalCuotas) && is_numeric($invoiceTotal) && is_float($totalCuotas) && is_float($invoiceTotal)) {
                                        $difference = $totalCuotas - $invoiceTotal;
                                    } else {
                                        $difference = 0.0; // Valor seguro por defecto
                                    }
                                    
                                    // SAFE DIFFERENCE FORMATTING
                                    $difference = (float) $difference;
                                    
                                    if (abs($difference) > 0.01) {
                                        $content .= "\n\n🚨 **ALERTA: CUOTAS DESINCRONIZADAS**";
                                        $content .= "\n⚠️ **Diferencia:** S/ " . number_format($difference, 2);
                                        if ($difference > 0) {
                                            $content .= " (Las cuotas exceden el total)";
                                        } else {
                                            $content .= " (Las cuotas son menores al total)";
                                        }
                                        $content .= "\n\n🔄 **SOLUCIÓN:** Use el botón 'Recalcular Cuotas' para sincronizar con el total actual.";
                                        $content .= "\n📝 **CAUSA:** El total de la factura cambió después de configurar las cuotas.";
                                    } else {
                                        $content .= "\n\n✅ **Las cuotas coinciden con el total de la factura**";
                                    }
                                    
                                    // Validación de fechas SUNAT
                                    $emissionDate = $get('emission_date');
                                    if ($emissionDate) {
                                        $invalidDates = false;
                                        foreach ($installments as $installment) {
                                            if (isset($installment['due_date'])) {
                                                try {
                                                    $dueDate = \Carbon\Carbon::parse($installment['due_date']);
                                                    if ($dueDate->lte(\Carbon\Carbon::parse($emissionDate))) {
                                                        $invalidDates = true;
                                                        break;
                                                    }
                                                } catch (\Exception $e) {
                                                    // Error de parsing de fecha - continuar con la siguiente
                                                    continue;
                                                }
                                            }
                                        }
                                        
                                        if ($invalidDates) {
                                            $content .= "\n\n🚨 **ERROR SUNAT**: Hay fechas de vencimiento iguales o anteriores a la fecha de emisión.";
                                            $content .= "\n   Esto causará el error 3267 al enviar a SUNAT.";
                                        } else {
                                            $content .= "\n\n✅ **Fechas válidas para SUNAT** (todas posteriores a emisión)";
                                        }
                                    }
                                    
                                    return $content;
                                    
                                } catch (\Exception $e) {
                                    // FALLBACK SEGURO: En caso de cualquier error no previsto
                                    return '⚠️ Error al generar resumen de cuotas. Verifique que los valores sean numéricos válidos.';
                                }
                            })
                            ->columnSpanFull(),
                    ]),
            ]);
    }    

    protected static function calculateItemTotals($set, $get): void
    {
        $quantity = (float) ($get('quantity') ?? 0);
        $unitValue = (float) ($get('unit_value') ?? 0);
        $discount = (float) ($get('discount') ?? 0);
        $igvType = $get('igv_type') ?? '1';
        $igvPercentage = (float) ($get('../../igv_percentage') ?? 18.00);

        // Calcular subtotal (sin IGV)
        $subtotal = ($quantity * $unitValue) - $discount;
        
        // Calcular IGV usando la fórmula: Subtotal * Porcentaje IGV
        $igv = 0;
        if ($igvType === '1' && $igvPercentage > 0) {
            $igv = $subtotal * ($igvPercentage / 100);
        }
        
        // Calcular total (subtotal + IGV)
        $total = $subtotal + $igv;
        
        // Calcular precio unitario con IGV: (Valor Unitario * (1 + IGV%))
        $unitPrice = 0;
        if ($igvType === '1' && $igvPercentage > 0) {
            $unitPrice = $unitValue * (1 + ($igvPercentage / 100));
        } else {
            $unitPrice = $unitValue;
        }

        $set('subtotal', round($subtotal, 2));
        $set('igv', round($igv, 2));
        $set('total', round($total, 2));
        $set('unit_price', round($unitPrice, 2));
        
        // NUEVO: Recalcular automáticamente el total general de la factura
        static::calculateInvoiceTotals($set, $get);
    }

    protected static function calculateInvoiceTotals($set, $get): void
    {
        $items = $get('../../items') ?? []; // Acceder a todos los items del formulario
        $totalTaxable = 0;
        $totalUnaffected = 0;
        $totalExonerated = 0;
        $totalIgv = 0;
        $totalGeneral = 0;
        $totalDiscount = (float) ($get('../../global_discount') ?? 0);

        foreach ($items as $item) {
            $itemTotal = (float) ($item['total'] ?? 0);
            $itemSubtotal = (float) ($item['subtotal'] ?? 0);
            $itemIgv = (float) ($item['igv'] ?? 0);
            $itemDiscount = (float) ($item['discount'] ?? 0);
            $itemIgvType = $item['igv_type'] ?? '1';

            $totalGeneral += $itemTotal;
            $totalIgv += $itemIgv;
            $totalDiscount += $itemDiscount;

            switch ($itemIgvType) {
                case '1':
                    $totalTaxable += $itemSubtotal;
                    break;
                case '8':
                    $totalExonerated += $itemSubtotal;
                    break;
                case '9':
                    $totalUnaffected += $itemSubtotal;
                    break;
            }
        }

        $set('../../total_taxable', round($totalTaxable, 2));
        $set('../../total_unaffected', round($totalUnaffected, 2));
        $set('../../total_exonerated', round($totalExonerated, 2));
        $set('../../total_igv', round($totalIgv, 2));
        $set('../../total_discount', round($totalDiscount, 2));
        $set('../../total', round($totalGeneral - $totalDiscount, 2)); // CRITICAL: Esto disparará el afterStateUpdated del total
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('series')
                    ->label('Serie')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('number')
                    ->label('Número')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('emission_date')
                    ->label('Fecha Emisión')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('total')
                    ->label('Total')
                    ->money('PEN')
                    ->sortable(),
                TextColumn::make('despatches_count')
                    ->label('Guías')
                    ->counts('despatches')
                    ->badge()
                    ->color('info'),
                TextColumn::make('sunat_accepted')
                    ->label('SUNAT')
                    ->badge()
                    ->color(fn ($state): string => match ($state) {
                        true => 'success',
                        false => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn ($state): string => match ($state) {
                        true => 'Aceptado',
                        false => 'Rechazado',
                        default => 'Pendiente',
                    }),
                TextColumn::make('sunat_response_code')
                    ->label('Cód. SUNAT')
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('client_id')
                    ->label('Cliente')
                    ->options(Client::all()->pluck('name', 'id'))
                    ->searchable(),
                Filter::make('emission_date')
                    ->form([
                        FilterDatePicker::make('emission_from')
                            ->label('Fecha desde'),
                        FilterDatePicker::make('emission_until')
                            ->label('Fecha hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['emission_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('emission_date', '>=', $date),
                            )
                            ->when(
                                $data['emission_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('emission_date', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['emission_from'] ?? null) {
                            $indicators['emission_from'] = 'Desde: ' . \Carbon\Carbon::parse($data['emission_from'])->format('d/m/Y');
                        }
                        if ($data['emission_until'] ?? null) {
                            $indicators['emission_until'] = 'Hasta: ' . \Carbon\Carbon::parse($data['emission_until'])->format('d/m/Y');
                        }
                        return $indicators;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('view_guides')
                    ->label('Ver Guías')
                    ->icon('heroicon-o-truck')
                    ->color('info')
                    ->modalHeading(fn (Invoice $record): string => "Guías de Remisión - Factura {$record->series}-{$record->number}")
                    ->modalContent(fn (Invoice $record): \Illuminate\Contracts\View\View => view('filament.modals.invoice-guides', [
                        'invoice' => $record->load(['despatches.client', 'despatches.company', 'despatches.items'])
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->visible(fn (Invoice $record): bool => $record->despatches->count() > 0),
                
                Tables\Actions\Action::make('enviar_nubefact')
                    ->label('Enviar a Nubefact')
                    ->icon('heroicon-o-paper-airplane')
                    ->action(fn (Invoice $record) => app(\App\Services\InvoiceService::class)->sendToNubefact($record))
                    ->requiresConfirmation()
                    ->color('primary'),
                
                Tables\Actions\Action::make('download_pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-text')
                    ->color('danger')
                    ->url(fn (Invoice $record): string => $record->pdf_link ?: $record->sunat_link ?: '#')
                    ->openUrlInNewTab()
                    ->visible(fn (Invoice $record): bool => !empty($record->pdf_link) || !empty($record->sunat_link)),

                Tables\Actions\Action::make('download_xml')
                    ->label('XML')
                    ->icon('heroicon-o-code-bracket')
                    ->color('success')
                    ->url(fn (Invoice $record): string => $record->xml_link)                    
                    ->openUrlInNewTab()
                    ->visible(fn (Invoice $record): bool => !empty($record->xml_link) || !empty($record->xml_zip_base64)),                                               

                Tables\Actions\Action::make('download_cdr')
                    ->label('CDR')
                    ->icon('heroicon-o-document-check')
                    ->color('warning')
                    ->url(fn (Invoice $record): string => $record->cdr_link)                     
                    ->openUrlInNewTab()
                    ->visible(fn (Invoice $record): bool => !empty($record->cdr_link) || !empty($record->cdr_zip_base64)),
            ])
            ->bulkActions([                
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
            'index' => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }

    /**
     * Genera el siguiente número correlativo para una serie dada
     */
    public static function getNextCorrelativeNumber(string $series): int
    {
        $lastInvoice = Invoice::where('series', $series)
            ->orderBy('number', 'desc')
            ->first();
        
        return $lastInvoice ? $lastInvoice->number + 1 : 1;
    }
}