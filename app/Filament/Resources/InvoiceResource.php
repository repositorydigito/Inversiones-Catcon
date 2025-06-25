<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InvoiceResource\Pages;
use App\Filament\Resources\InvoiceResource\RelationManagers;
use App\Models\Invoice;
use App\Models\Client;
use App\Models\MeasureUnit;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

// Componentes de Filament Forms
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Section; 

// Componentes de Filament Tables
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\DateRangeFilter;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationLabel = 'Facturas';
    protected static ?string $pluralModelLabel = 'Facturas';
    protected static ?string $modelLabel = 'Factura';
    //protected static ?int $navigationSort = 3;
    //protected static ?string $navigationGroup = 'Entidades'; 

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Datos de la Factura')
                    ->description('Información general del comprobante.')
                    ->columns(3) // Distribuye los campos en 3 columnas
                    ->schema([
                        TextInput::make('series')
                            ->required()
                            ->maxLength(4)
                            ->default('F001') // Valor por defecto, ajusta según tu negocio
                            ->label('Serie')
                            ->columnSpan(1), // Ocupa 1 de las 3 columnas
                        TextInput::make('number')
                            ->required()
                            ->numeric()
                            ->label('Número')
                            ->columnSpan(1),
                        Select::make('invoice_type')
                            ->label('Tipo de Comprobante')
                            ->options([
                                '01' => 'Factura',
                                '03' => 'Boleta de Venta',
                                '07' => 'Nota de Crédito',
                                '08' => 'Nota de Débito',
                                // Agrega más tipos según los códigos de SUNAT y Nubefact
                            ])
                            ->required()
                            ->default('01')
                            ->columnSpan(1),
                        Select::make('transaction_type')
                            ->label('Tipo de Transacción SUNAT')
                            ->options([
                                '01' => 'Venta Interna',
                                '02' => 'Venta - Exportación',
                                '03' => 'Venta - NO DOMICILIADO',
                                // Agrega más tipos según la documentación de SUNAT
                            ])
                            ->required()
                            ->default('01')
                            ->columnSpan(1),
                        DatePicker::make('emission_date')
                            ->label('Fecha de Emisión')
                            ->required()
                            ->default(now()) // Fecha actual por defecto
                            ->native(false) // Usa el selector de fecha de Filament/Livewire, mejor UX
                            ->columnSpan(1),
                        DatePicker::make('due_date')
                            ->label('Fecha de Vencimiento')
                            ->nullable() // Puede ser nulo
                            ->native(false)
                            ->columnSpan(1),
                        Select::make('currency')
                            ->label('Moneda')
                            ->options([
                                '1' => 'SOLES (PEN)', // Código para PEN en Nubefact
                                '2' => 'DÓLARES (USD)', // Código para USD en Nubefact
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
                            ->columnSpanFull(), // Ocupa todas las columnas disponibles en la sección
                    ]),

                Section::make('Datos del Cliente')
                    ->description('Selecciona el cliente para esta factura.')
                    ->columns(2)
                    ->schema([
                        Select::make('client_id')
                            ->label('Seleccionar Cliente')
                            ->options(Client::all()->pluck('name', 'id')) // Carga todos los clientes de tu tabla Client
                            ->searchable() // Permite buscar clientes por su nombre
                            ->required()
                            ->afterStateUpdated(function ($state, $set) {
                                // Cuando seleccionas un cliente, intenta precargar sus datos
                                $client = Client::find($state);
                                if ($client) {
                                    $set('client_document_type', $client->document_type);
                                    $set('client_document_number', $client->document_number);
                                    $set('client_name', $client->name);
                                    $set('client_address', $client->address);
                                    $set('client_email', $client->email);
                                } else {
                                    // Limpiar campos si el cliente se deselecciona o no se encuentra
                                    $set('client_document_type', null);
                                    $set('client_document_number', null);
                                    $set('client_name', null);
                                    $set('client_address', null);
                                    $set('client_email', null);
                                }
                            })
                            ->live() // Hace que el `afterStateUpdated` se dispare al cambiar la selección
                            ->columnSpanFull(),

                        // Campos ocultos o solo lectura que se rellenan automáticamente
                        // Son necesarios para el payload de Nubefact
                        TextInput::make('client_document_type')
                            ->label('Tipo Doc. Cliente (SUNAT)')
                            ->readOnly() // El usuario no lo edita directamente
                            ->required()
                            ->disabled(fn (string $operation): bool => $operation !== 'create') // Editable solo al crear si no quieres que se cambie en edición
                            ->dehydrated(fn ($state) => filled($state)), // Asegura que el valor se guarde incluso si es readonly
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
                    ->description('Añade los productos o servicios de la factura.')
                    ->schema([
                        Repeater::make('items')
                            //->relationship('items') 
                            ->label('') 
                            ->schema([
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
                                    ->columnSpan(2),
                                TextInput::make('quantity')
                                    ->label('Cantidad')
                                    ->required()
                                    ->numeric()
                                    ->step(0.01)
                                    ->default(1)
                                    ->live() // Para calcular los totales al cambiar la cantidad
                                    ->columnSpan(1),
                                TextInput::make('unit_value')
                                    ->label('Valor Unitario (sin IGV)')
                                    ->required()
                                    ->numeric()
                                    ->step(0.01)
                                    ->live() // Para calcular los totales al cambiar el valor unitario
                                    ->columnSpan(1),
                                TextInput::make('unit_price')
                                    ->label('Precio Unitario (con IGV)')
                                    ->required()
                                    ->numeric()
                                    ->step(0.01)
                                    ->live() // Para calcular los totales al cambiar el precio unitario
                                    ->columnSpan(1),
                                TextInput::make('discount')
                                    ->label('Descuento por Ítem')
                                    ->numeric()
                                    ->step(0.01)
                                    ->default(0.00)
                                    ->live() // Para calcular los totales al cambiar el descuento
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
                                    ->live() // Para recalcular IGV y total al cambiar el tipo
                                    ->columnSpan(1),
                                /* TextInput::make('subtotal')
                                    ->label('Subtotal Ítem (sin IGV)')
                                    ->numeric()
                                    ->readOnly()
                                    ->reactive() // Se actualiza si los campos "live" cambian
                                    ->afterStateHydrated(function ($state, $set, $get) {
                                        // Calcular al cargar el formulario
                                        self::calculateItemTotals($set, $get);
                                    })
                                    ->dehydrated(fn ($state) => filled($state)) // Asegura que el valor se guarde
                                    ->columnSpan(1),
                                TextInput::make('igv')
                                    ->label('IGV Ítem')
                                    ->numeric()
                                    ->readOnly()
                                    ->reactive()
                                    ->afterStateHydrated(function ($state, $set, $get) {
                                        self::calculateItemTotals($set, $get);
                                    })
                                    ->dehydrated(fn ($state) => filled($state))
                                    ->columnSpan(1),
                                TextInput::make('total')
                                    ->label('Total Ítem (con IGV)')
                                    ->numeric()
                                    ->readOnly()
                                    ->reactive()
                                    ->afterStateHydrated(function ($state, $set, $get) {
                                        self::calculateItemTotals($set, $get);
                                    })
                                    ->dehydrated(fn ($state) => filled($state))
                                    ->columnSpan(1), */
                            ])
                            ->columns(4) 
                            ->defaultItems(1) 
                            ->minItems(1) 
                            ->reorderable()
                            ->collapsible() 
                            ->cloneable() 
                            ->addActionLabel('Añadir Nuevo Ítem')                            
                            ->columnSpanFull(), 
                    ]),

                /* Section::make('Totales de la Factura')
                    ->description('Resumen de los montos calculados.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('total_gravada')
                            ->label('Total Gravada (S/.)')
                            ->numeric()
                            ->readOnly()
                            ->default(0.00)
                            ->live() // Para que Filament recalcule al cambiar items
                            ->dehydrated(fn ($state) => filled($state)), // Asegura que se guarde
                        TextInput::make('total_inafecta')
                            ->label('Total Inafecta (S/.)')
                            ->numeric()
                            ->readOnly()
                            ->default(0.00)
                            ->live()
                            ->dehydrated(fn ($state) => filled($state)),
                        TextInput::make('total_exonerada')
                            ->label('Total Exonerada (S/.)')
                            ->numeric()
                            ->readOnly()
                            ->default(0.00)
                            ->live()
                            ->dehydrated(fn ($state) => filled($state)),
                        TextInput::make('total_igv')
                            ->label('Total IGV (S/.)')
                            ->numeric()
                            ->readOnly()
                            ->default(0.00)
                            ->live()
                            ->dehydrated(fn ($state) => filled($state)),
                        TextInput::make('total_discount')
                            ->label('Total Descuento (S/.)')
                            ->numeric()
                            ->readOnly()
                            ->default(0.00)
                            ->live()
                            ->dehydrated(fn ($state) => filled($state)),
                        TextInput::make('total')
                            ->label('TOTAL GENERAL (S/.)')
                            ->numeric()
                            ->readOnly()
                            ->default(0.00)
                            ->live()
                            ->dehydrated(fn ($state) => filled($state)),
                    ])
                    // Agrega aquí un `afterStateUpdated` para la sección de items,
                    // que recalcule todos estos totales de la factura
                    ->afterStateUpdated(function ($state, $set, $get) {
                        self::calculateInvoiceTotals($set, $get);
                    }),*/
            ]); 
    }

    protected static function calculateItemTotals($set, $get): void
    {
        $quantity = (float) $get('quantity');
        $unitValue = (float) $get('unit_value');
        $unitPrice = (float) $get('unit_price');
        $discount = (float) $get('discount');
        $igvType = $get('igv_type');
        $igvPercentage = (float) $get('../../igv_percentage'); 

        $subtotal = ($quantity * $unitValue) - $discount;
        $igv = 0;
        $total = ($quantity * $unitPrice) - $discount;

        if ($igvType === '1' && $igvPercentage > 0) {            
            $igv = $total - $subtotal;
        }

        $set('subtotal', round($subtotal, 2));
        $set('igv', round($igv, 2));
        $set('total', round($total, 2));
    }


    protected static function calculateInvoiceTotals($set, $get): void
    {
        $items = $get('items');
        $totalGravada = 0;
        $totalInafecta = 0;
        $totalExonerada = 0;
        $totalIgv = 0;
        $totalGeneral = 0;
        $totalDescuento = (float) $get('global_discount'); // Incluir descuento global

        if (is_array($items)) {
            foreach ($items as $item) {
                $itemTotal = (float) ($item['total'] ?? 0);
                $itemSubtotal = (float) ($item['subtotal'] ?? 0);
                $itemIgv = (float) ($item['igv'] ?? 0);
                $itemDiscount = (float) ($item['discount'] ?? 0);
                $itemIgvType = $item['igv_type'] ?? '1';

                $totalGeneral += $itemTotal;
                $totalIgv += $itemIgv;
                $totalDescuento += $itemDiscount; // Sumar descuentos por ítem

                switch ($itemIgvType) {
                    case '1': // Gravado
                        $totalGravada += $itemSubtotal;
                        break;
                    case '8': // Exonerado
                        $totalExonerada += $itemSubtotal;
                        break;
                    case '9': // Inafecto
                        $totalInafecta += $itemSubtotal;
                        break;
                }
            }
        }

        // Sumar el descuento global al total de descuentos de ítems
        $totalDescuento += (float) $get('global_discount');

        $set('total_gravada', round($totalGravada, 2));
        $set('total_inafecta', round($totalInafecta, 2));
        $set('total_exonerada', round($totalExonerada, 2));
        $set('total_igv', round($totalIgv, 2));
        $set('total_discount', round($totalDescuento, 2));
        $set('total', round($totalGeneral - $totalDescuento, 2)); // Total general menos todos los descuentos
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
                    ->label('Total (S/.)')
                    ->money('PEN') 
                    ->sortable(),
                TextColumn::make('sunat_accepted')
                    ->label('Aceptado SUNAT')
                    ->badge() 
                    ->color(fn (bool $state): string => match ($state) {
                        true => 'success',
                        false => 'danger',
                        default => 'warning', 
                    })
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Sí' : 'No'), 
                TextColumn::make('sunat_response_code')
                    ->label('Cód. SUNAT')
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }
}
