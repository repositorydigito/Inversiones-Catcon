<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Models\Client;
use App\Models\Invoice;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Model;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Acción para generar PDF local
            Actions\Action::make('generate_pdf')
                ->label('Generar PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->url(fn (): string => route('invoice.pdf', $this->record))
                ->openUrlInNewTab()
                ->color('danger')
                ->tooltip('Generar y descargar PDF local de la factura'),
            
            // Acción para enviar a Nubefact
            Actions\Action::make('send_to_nubefact')
                ->label('Enviar a Nubefact')
                ->icon('heroicon-o-paper-airplane')
                ->action(function () {
                    try {
                        app(\App\Services\InvoiceService::class)->sendToNubefact($this->record);
                        \Filament\Notifications\Notification::make()
                            ->title('Factura enviada a Nubefact')
                            ->body("La factura {$this->record->series}-{$this->record->number} se envió correctamente.")
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        \Filament\Notifications\Notification::make()
                            ->title('Error al enviar a Nubefact')
                            ->body('Error: ' . $e->getMessage())
                            ->danger()
                            ->send();
                    }
                })
                ->requiresConfirmation()
                ->modalHeading('Confirmar envío a Nubefact')
                ->modalDescription('¿Está seguro de que desea enviar esta factura a Nubefact OSE?')
                ->color('primary'),
            
            // Acción de eliminar
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Sobrescribir el formulario para incluir la lógica de cálculo automático de cuotas
     */
    public function form(Form $form): Form
    {
        // Obtener el formulario base del Resource
        $baseForm = parent::form($form);
        
        // Modificar solo la sección de cuotas para agregar la lógica que falta en edición
        return $baseForm->schema([
            // Mantener todos los campos base del Resource
            ...$this->getInvoiceFormSchema(),
        ]);
    }

    /**
     * Obtiene el esquema del formulario con la lógica de cuotas mejorada para edición
     */
    protected function getInvoiceFormSchema(): array
    {
        // Usar el schema base del Resource pero con modificaciones específicas para edición
        $baseSchema = InvoiceResource::form(\Filament\Forms\Form::make())->getSchema();
        
        // Buscar y reemplazar la sección de configuración de cuotas
        return $this->enhanceInstallmentsSection($baseSchema);
    }

    /**
     * Mejora la sección de cuotas para incluir cálculo automático en edición
     */
    protected function enhanceInstallmentsSection(array $schema): array
    {
        foreach ($schema as $index => $component) {
            // Buscar la sección de Configuración de Cuotas
            if ($component instanceof Section && 
                method_exists($component, 'getLabel') && 
                $component->getLabel() === 'Configuración de Cuotas') {
                
                // Reemplazar con versión mejorada
                $schema[$index] = $this->createEnhancedInstallmentsSection();
            }
        }
        
        return $schema;
    }

    /**
     * Crea la sección mejorada de configuración de cuotas
     */
    protected function createEnhancedInstallmentsSection(): Section
    {
        return Section::make('Configuración de Cuotas')
            ->description('Configure las cuotas de pago para facturas a crédito.')
            ->visible(fn ($get) => $get('is_credit_payment'))
            ->columns(1)
            ->schema([
                // Campo auxiliar para número de cuotas
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
                    ->default(fn ($get) => count($get('installments') ?? []) ?: 1)
                    ->live()
                    ->afterStateUpdated(function ($state, $set, $get) {
                        $this->handleInstallmentsCalculation($state, $set, $get);
                    })
                    ->helperText('Al cambiar el número de cuotas se auto-calculará la división del monto total')
                    ->columnSpanFull(),
                    
                Repeater::make('installments')
                    ->label('Cuotas de Pago')
                    ->relationship('installments') // En EDIT sí podemos usar relationship
                    ->schema([
                        Hidden::make('installment_number'),
                        Hidden::make('order'),
                        
                        Grid::make(2)->schema([
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
                    ])
                    ->defaultItems(1)
                    ->minItems(1)
                    ->maxItems(12)
                    ->reorderable(false)
                    ->addActionLabel('Agregar Cuota')
                    ->deleteAction(
                        fn (\Filament\Forms\Components\Actions\Action $action) => $action->requiresConfirmation()
                    )
                    ->itemLabel(fn (array $state): ?string => 
                        ($state['installment_number'] ?? 'Nueva cuota') . ': S/ ' . number_format($state['amount'] ?? 0, 2)
                    ),
                    
                Placeholder::make('installments_summary')
                    ->label('Resumen de Cuotas')
                    ->content(function ($get) {
                        return $this->generateInstallmentsSummary($get);
                    })
            ]);
    }

    /**
     * Maneja el cálculo automático de cuotas cuando cambia el número de cuotas
     */
    protected function handleInstallmentsCalculation($state, $set, $get): void
    {
        $numberOfInstallments = (int) $state;
        $total = (float) ($get('total') ?? 0);
        $emissionDate = $get('emission_date');
        
        Log::info('EditInvoice: Calculando cuotas automáticamente', [
            'numberOfInstallments' => $numberOfInstallments,
            'total' => $total,
            'emissionDate' => $emissionDate
        ]);
        
        if ($total <= 0 || !$emissionDate) {
            Log::warning('EditInvoice: No se puede calcular - datos insuficientes');
            return;
        }
        
        // Generar cuotas automáticamente
        $installments = $this->generateAutomaticInstallments(
            $numberOfInstallments, 
            $total, 
            $emissionDate
        );
        
        // Aplicar al formulario
        $set('installments', $installments);
        $set('due_date', end($installments)['due_date']);
        
        // Notificación al usuario
        Notification::make()
            ->title('✅ Cuotas recalculadas automáticamente')
            ->body("Se generaron {$numberOfInstallments} cuotas de S/ " . 
                   number_format($installments[0]['amount'], 2))
            ->success()
            ->duration(3000)
            ->send();
            
        Log::info('EditInvoice: Cuotas calculadas exitosamente', [
            'installments_count' => count($installments),
            'first_amount' => $installments[0]['amount'] ?? 0
        ]);
    }

    /**
     * Genera cuotas automáticamente dividiendo el total entre el número de cuotas
     */
    protected function generateAutomaticInstallments(int $count, float $total, string $emissionDate): array
    {
        $amountPerInstallment = round($total / $count, 2);
        $lastInstallmentAmount = $total - ($amountPerInstallment * ($count - 1));
        $baseDate = Carbon::parse($emissionDate);
        $installments = [];
        
        for ($i = 0; $i < $count; $i++) {
            $amount = ($i === $count - 1) ? $lastInstallmentAmount : $amountPerInstallment;
            
            // CRITICAL FIX: Asegurar fechas POSTERIORES a emission_date para evitar SUNAT 3267
            if ($count === 1) {
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
        
        return $installments;
    }

    /**
     * Genera el resumen visual de las cuotas
     */
    protected function generateInstallmentsSummary($get): string
    {
        $installments = $get('installments') ?? [];
        $invoiceTotal = (float) ($get('total') ?? 0);
        $emissionDate = $get('emission_date');
        
        if (empty($installments) || $invoiceTotal <= 0) {
            return '⏳ Configure las cuotas para ver el resumen.';
        }
        
        $totalCuotas = 0;
        $content = "**📋 Cuotas configuradas:**\n";
        
        $baseDate = $emissionDate ? Carbon::parse($emissionDate) : Carbon::now();
        
        foreach ($installments as $index => $installment) {
            $amount = (float) ($installment['amount'] ?? 0);
            $dueDate = $installment['due_date'] ?? null;
            $totalCuotas += $amount;
            
            $dueDateFormatted = $dueDate ? Carbon::parse($dueDate)->format('d/m/Y') : 'Sin fecha';
            
            // Validar fecha
            $dateIcon = '✅';
            if ($dueDate && $emissionDate) {
                $dueDateCarbon = Carbon::parse($dueDate);
                if ($dueDateCarbon->lte($baseDate)) {
                    $dateIcon = '🚨';
                }
            }
            
            $content .= "• Cuota " . ($index + 1) . ": S/ " . number_format($amount, 2) . 
                       " (Vence: {$dueDateFormatted}) {$dateIcon}\n";
        }
        
        $content .= "\n**💰 Total cuotas:** S/ " . number_format($totalCuotas, 2) . "\n";
        $content .= "**🧾 Total factura:** S/ " . number_format($invoiceTotal, 2) . "\n";
        
        $difference = abs($totalCuotas - $invoiceTotal);
        
        if ($difference <= 0.01) {
            $content .= "**✅ Las cuotas coinciden con el total de la factura**\n";
        } else {
            $content .= "**⚠️ ADVERTENCIA: Diferencia de S/ " . number_format($difference, 2) . "**\n";
        }
        
        // Validación de fechas SUNAT
        $validDates = true;
        if ($emissionDate) {
            foreach ($installments as $installment) {
                if ($installment['due_date'] && 
                    Carbon::parse($installment['due_date'])->lte(Carbon::parse($emissionDate))) {
                    $validDates = false;
                    break;
                }
            }
        }
        
        if ($validDates) {
            $content .= "**✅ Fechas válidas para SUNAT** (todas posteriores a emisión)";
        } else {
            $content .= "**🚨 ERROR: Algunas fechas son anteriores o iguales a emisión (Error SUNAT 3267)**";
        }
        
        return $content;
    }

    /**
     * Maneja la actualización del registro con validaciones específicas para edición
     */
    protected function handleRecordUpdate(Model $record, array $data): Invoice
    {
        Log::info('=== INICIANDO ACTUALIZACIÓN DE FACTURA ===', [
            'invoice_id' => $record->id,
            'series' => $data['series'] ?? 'N/A',
            'number' => $data['number'] ?? 'N/A',
            'total_inicial' => $data['total'] ?? 'NO DEFINIDO',
            'items_count' => count($data['items'] ?? []),
            'installments_count' => count($data['installments'] ?? [])
        ]);
        
        // Crear o actualizar cliente si es necesario
        if (isset($data['client_document_number'])) {
            $client = $this->handleClientCreation($data);
            $data['client_id'] = $client->id;
        }
        
        // Calcular totales finales
        $this->calculateFinalTotals($data);
        
        // Validar cuotas para evitar Error SUNAT 3267
        $this->validateInstallments($data);
        
        // Actualizar en transacción
        return DB::transaction(function () use ($record, $data) {
            // Limpiar campos que no van en la tabla principal
            $invoiceData = collect($data)->except([
                'items', 
                'installments',
                'selected_despatches',
                'number_of_installments',
                'is_credit_payment',
                'detraction_service_name',
                'detraction_payment_method_name',
            ])->toArray();
            
            // Actualizar la factura
            $record->update($invoiceData);
            
            // Actualizar items (eliminar existentes y crear nuevos)
            if (isset($data['items']) && is_array($data['items'])) {
                $record->items()->delete();
                foreach ($data['items'] as $itemData) {
                    $record->items()->create($itemData);
                }
                Log::info('Items actualizados:', ['count' => count($data['items'])]);
            }
            
            // Actualizar cuotas (eliminar existentes y crear nuevas)
            if (isset($data['installments']) && is_array($data['installments'])) {
                $record->installments()->delete();
                foreach ($data['installments'] as $installmentData) {
                    // Limpiar campos que no van en BD
                    unset($installmentData['id']);
                    $record->installments()->create($installmentData);
                }
                Log::info('Cuotas actualizadas:', [
                    'count' => count($data['installments']),
                    'fechas' => array_column($data['installments'], 'due_date')
                ]);
            }
            
            return $record->refresh();
        });
    }

    /**
     * Métodos helper copiados de CreateInvoice para mantener consistencia
     */
    protected function handleClientCreation(array $data): Client
    {
        Log::info('EditInvoice: Procesando cliente:', [
            'document_number' => $data['client_document_number'] ?? 'N/A',
            'name' => $data['client_name'] ?? 'N/A'
        ]);

        return Client::firstOrCreate(
            ['document_number' => $data['client_document_number']],
            [
                'name' => $data['client_name'],
                'document_type' => $data['client_document_type'],
                'address' => $data['client_address'] ?? null,
                'email' => $data['client_email'] ?? null,
            ]
        );
    }

    /**
     * Calcula los totales finales de la factura
     */
    protected function calculateFinalTotals(array &$data): void
    {
        $items = $data['items'] ?? [];
        $globalDiscount = (float) ($data['global_discount'] ?? 0.00);
        
        Log::info('EditInvoice: Calculando totales finales:', [
            'items_count' => count($items),
            'global_discount' => $globalDiscount
        ]);
        
        $totalTaxable = 0;
        $totalUnaffected = 0;
        $totalExonerated = 0;
        $totalIgv = 0;
        $totalItems = 0;
        $totalItemsDiscount = 0;

        foreach ($items as $item) {
            $itemTotal = (float) ($item['total'] ?? 0);
            $itemSubtotal = (float) ($item['subtotal'] ?? 0);
            $itemIgv = (float) ($item['igv'] ?? 0);
            $itemDiscount = (float) ($item['discount'] ?? 0);
            $itemIgvType = $item['igv_type'] ?? '1';

            $totalItems += $itemTotal;
            $totalIgv += $itemIgv;
            $totalItemsDiscount += $itemDiscount;

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

        // Actualizar totales
        $data['total_taxable'] = round($totalTaxable, 2);
        $data['total_unaffected'] = round($totalUnaffected, 2);
        $data['total_exonerated'] = round($totalExonerated, 2);
        $data['total_igv'] = round($totalIgv, 2);
        $data['total_discount'] = round($globalDiscount + $totalItemsDiscount, 2);
        $data['total'] = round($totalItems - $globalDiscount, 2);

        Log::info('EditInvoice: Totales calculados:', [
            'total_final' => $data['total'],
            'total_taxable' => $data['total_taxable'],
            'total_igv' => $data['total_igv']
        ]);
    }

    /**
     * Valida que las cuotas sean correctas para facturas a crédito
     */
    protected function validateInstallments(array &$data): void
    {
        Log::info('EditInvoice: Validando cuotas para SUNAT 3267:', [
            'due_date' => $data['due_date'] ?? 'NO DEFINIDO',
            'installments_count' => count($data['installments'] ?? []),
            'emission_date' => $data['emission_date'] ?? 'NO DEFINIDO'
        ]);
        
        // Solo validar si es factura a crédito
        if (empty($data['due_date'])) {
            Log::info('EditInvoice: Factura de CONTADO - sin validación de cuotas');
            return;
        }
        
        $installments = $data['installments'] ?? [];
        $invoiceTotal = (float) ($data['total'] ?? 0);
        $emissionDate = Carbon::parse($data['emission_date']);
        
        // Validar y corregir fechas si es necesario
        foreach ($installments as $index => &$installment) {
            $dueDate = Carbon::parse($installment['due_date']);
            
            // CRITICAL VALIDATION: Error SUNAT 3267
            if ($dueDate->lte($emissionDate)) {
                $correctedDate = $emissionDate->copy()->addDays(($index + 1) * 7);
                $installment['due_date'] = $correctedDate->format('Y-m-d');
                
                Log::warning('EditInvoice: FECHA CORREGIDA AUTOMÁTICAMENTE:', [
                    'cuota' => $index + 1,
                    'fecha_original' => $dueDate->format('Y-m-d'),
                    'fecha_corregida' => $correctedDate->format('Y-m-d')
                ]);
            }
            
            // Asegurar números de cuota
            if (empty($installment['installment_number'])) {
                $installment['installment_number'] = 'Cuota' . str_pad($index + 1, 3, '0', STR_PAD_LEFT);
            }
            if (empty($installment['order'])) {
                $installment['order'] = $index + 1;
            }
        }
        
        // Validar suma de montos
        if ($invoiceTotal > 0) {
            $totalInstallments = array_sum(array_column($installments, 'amount'));
            $difference = abs($totalInstallments - $invoiceTotal);
            
            if ($difference > 0.01) {
                throw new \Exception(
                    "EditInvoice: La suma de las cuotas (S/ " . number_format($totalInstallments, 2) . 
                    ") no coincide con el total de la factura (S/ " . number_format($invoiceTotal, 2) . "). " .
                    "Diferencia: S/ " . number_format($difference, 2)
                );
            }
        }
        
        Log::info('EditInvoice: Cuotas validadas correctamente');
    }
}
