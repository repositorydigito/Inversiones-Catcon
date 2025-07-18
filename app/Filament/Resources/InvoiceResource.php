<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InvoiceResource\Pages;
use App\Filament\Resources\InvoiceResource\RelationManagers;
use App\Models\Invoice;
use App\Models\Client;
use App\Models\MeasureUnit;
use App\Models\Despatch;
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
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Checkbox;

// Componentes de Filament Tables
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
                    ->description('Selecciona las guías de remisión para generar la factura automáticamente.')
                    ->columns(1)
                    ->schema([
                        Select::make('selected_despatches')
                            ->label('Guías de Remisión Disponibles')
                            ->multiple()
                            ->options(function () {
                                return Despatch::with('client')
                                    ->whereDoesntHave('invoices') // Solo guías que no tienen factura
                                    ->where('accepted_by_sunat', true) // Solo guías aceptadas por SUNAT
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
                            ->afterStateUpdated(function ($state, $set, $get) {
                                if (!empty($state) && !$get('manual_mode')) {
                                    static::fillFromDespatches($state, $set, $get);
                                }
                            })
                            ->helperText('Selecciona una o más guías de remisión. Los datos se cargarán automáticamente.')
                            ->columnSpanFull(),
                        
                        /* Checkbox::make('manual_mode')
                            ->label('Modo Manual')
                            ->helperText('Activa esta opción si quieres llenar los campos manualmente sin usar guías.')
                            ->live()
                            ->afterStateUpdated(function ($state, $set) {
                                if ($state) {
                                    // Limpiar selección de guías si se activa modo manual
                                    $set('selected_despatches', []);
                                    // Limpiar items
                                    $set('items', []);
                                    // Limpiar datos del cliente
                                    $set('client_id', null);
                                    $set('client_document_type', null);
                                    $set('client_document_number', null);
                                    $set('client_name', null);
                                    $set('client_address', null);
                                    $set('client_email', null);
                                }
                            }), */
                    ])
                    ->visible(fn (string $operation): bool => $operation === 'create'),

                /* Section::make('Guías Seleccionadas')
                    ->description('Resumen de las guías que serán incluidas en esta factura.')
                    ->schema([
                        Placeholder::make('despatch_summary')
                            ->label('')
                            ->content(function ($get) {
                                $selectedDespatches = $get('selected_despatches');
                                if (empty($selectedDespatches)) {
                                    return 'No hay guías seleccionadas.';
                                }
                                
                                $despatches = Despatch::whereIn('id', $selectedDespatches)
                                    ->with(['client', 'items'])
                                    ->get();
                                
                                $content = "📋 **Guías seleccionadas:**\n\n";
                                $totalItems = 0;
                                $totalQuantity = 0;
                                
                                foreach ($despatches as $despatch) {
                                    $itemsCount = $despatch->items->count();
                                    $quantity = $despatch->items->sum('quantity');
                                    $totalItems += $itemsCount;
                                    $totalQuantity += $quantity;
                                    
                                    $content .= "• **GR {$despatch->series}-{$despatch->number}** - {$despatch->client->name}\n";
                                    $content .= "  📅 {$despatch->emission_date->format('d/m/Y')} | 📦 {$itemsCount} item(s) | 🔢 {$quantity} unidades\n\n";
                                }
                                
                                $content .= "---\n";
                                $content .= "**Total: {$totalItems} items, {$totalQuantity} unidades**";
                                
                                return $content;
                            })
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($get) => !empty($get('selected_despatches')))
                    ->collapsible(), */

                Section::make('Datos de la Factura')
                    ->description('Información general del comprobante.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('series')
                            ->required()
                            ->maxLength(4)
                            ->default('FFF1')
                            ->label('Serie')
                            ->columnSpan(1),
                        TextInput::make('number')
                            ->required()
                            ->numeric()
                            ->label('Número')
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
                                '01' => 'Venta Interna',
                                '02' => 'Venta - Exportación',
                                '03' => 'Venta - NO DOMICILIADO',
                            ])
                            ->required()
                            ->default('01')
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

                        // Campos que se llenan automáticamente desde el cliente
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
                                    ->live()
                                    ->afterStateUpdated(function ($state, $set, $get) {
                                        static::calculateItemTotals($set, $get);
                                    })
                                    ->columnSpan(1),
                                TextInput::make('unit_value')
                                    ->label('Valor Unitario (sin IGV)')
                                    ->required()
                                    ->numeric()
                                    ->step(0.01)
                                    ->live()
                                    ->afterStateUpdated(function ($state, $set, $get) {
                                        static::calculateItemTotals($set, $get);
                                    })
                                    ->columnSpan(1),
                                TextInput::make('unit_price')
                                    ->label('Precio Unitario (con IGV)')
                                    ->required()
                                    ->numeric()
                                    ->step(0.01)
                                    ->live()
                                    ->afterStateUpdated(function ($state, $set, $get) {
                                        static::calculateItemTotals($set, $get);
                                    })
                                    ->columnSpan(1),
                                TextInput::make('discount')
                                    ->label('Descuento por Ítem')
                                    ->numeric()
                                    ->step(0.01)
                                    ->default(0.00)
                                    ->live()
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
                                // Campos calculados (readonly)
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
                            ->columns(4)
                            ->defaultItems(1)
                            ->minItems(1)
                            ->reorderable()
                            ->collapsible()
                            ->cloneable()
                            ->addActionLabel('Añadir Nuevo Ítem')
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
                            ->dehydrated(),
                    ]),
            ]);
    }

    /**
     * Llena los campos de la factura basándose en las guías seleccionadas
     */
    protected static function fillFromDespatches(array $despatchIds, $set, $get): void
    {
        if (empty($despatchIds)) {
            return;
        }

        $despatches = Despatch::whereIn('id', $despatchIds)
            ->with(['client', 'items.unitOfMeasure'])
            ->get();

        if ($despatches->isEmpty()) {
            return;
        }

        // Verificar que todas las guías pertenezcan al mismo cliente
        $clientIds = $despatches->pluck('client_id')->unique();
        if ($clientIds->count() > 1) {
            // Mostrar error y limpiar selección
            $set('selected_despatches', []);
            return;
        }

        // Tomar el cliente del primer despatch
        $firstDespatch = $despatches->first();
        $client = $firstDespatch->client;

        // Llenar datos del cliente
        $set('client_id', $client->id);
        $set('client_document_type', $client->document_type);
        $set('client_document_number', $client->document_number);
        $set('client_name', $client->name);
        $set('client_address', $client->address);
        $set('client_email', $client->email);

        // Combinar todos los items de todas las guías
        $allItems = [];
        foreach ($despatches as $despatch) {
            foreach ($despatch->items as $item) {
                $allItems[] = [
                    'unit_of_measure_id' => $item->unit_of_measure_id,
                    'code' => $item->code,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_value' => 10.00, // Valor por defecto, el usuario puede modificar
                    'unit_price' => 11.80, // Precio con IGV por defecto
                    'discount' => 0.00,
                    'igv_type' => '1', // Gravado por defecto
                    'subtotal' => $item->quantity * 10.00,
                    'igv' => $item->quantity * 1.80,
                    'total' => $item->quantity * 11.80,
                ];
            }
        }

        // Agrupar items similares (mismo código y descripción)
        $groupedItems = [];
        foreach ($allItems as $item) {
            $key = ($item['code'] ?? '') . '|' . $item['description'];
            if (isset($groupedItems[$key])) {
                $groupedItems[$key]['quantity'] += $item['quantity'];
                // Recalcular totales para el item agrupado
                $groupedItems[$key]['subtotal'] = $groupedItems[$key]['quantity'] * $groupedItems[$key]['unit_value'];
                $groupedItems[$key]['total'] = $groupedItems[$key]['quantity'] * $groupedItems[$key]['unit_price'];
                $groupedItems[$key]['igv'] = $groupedItems[$key]['total'] - $groupedItems[$key]['subtotal'];
            } else {
                $groupedItems[$key] = $item;
            }
        }

        $set('items', array_values($groupedItems));

        // Calcular totales de la factura
        static::calculateInvoiceTotals($set, $get);
    }

    protected static function calculateItemTotals($set, $get): void
    {
        $quantity = (float) ($get('quantity') ?? 0);
        $unitValue = (float) ($get('unit_value') ?? 0);
        $unitPrice = (float) ($get('unit_price') ?? 0);
        $discount = (float) ($get('discount') ?? 0);
        $igvType = $get('igv_type') ?? '1';
        $igvPercentage = (float) ($get('../../igv_percentage') ?? 18.00);

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
        $items = $get('items') ?? [];
        $totalTaxable = 0;
        $totalUnaffected = 0;
        $totalExonerated = 0;
        $totalIgv = 0;
        $totalGeneral = 0;
        $totalDiscount = (float) ($get('global_discount') ?? 0);

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

        $set('total_taxable', round($totalTaxable, 2));
        $set('total_unaffected', round($totalUnaffected, 2));
        $set('total_exonerated', round($totalExonerated, 2));
        $set('total_igv', round($totalIgv, 2));
        $set('total_discount', round($totalDiscount, 2));
        $set('total', round($totalGeneral - $totalDiscount, 2));
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
}