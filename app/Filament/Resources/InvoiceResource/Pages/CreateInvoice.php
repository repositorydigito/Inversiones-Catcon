<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\Client;
use App\Models\Despatch;
use App\Services\InvoiceService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function handleRecordCreation(array $data): Invoice
    {
        $client = $this->handleClientCreation($data);
        $data['client_id'] = $client->id;

        $selectedDespatches = $data['selected_despatches'] ?? [];

        $this->calculateFinalTotals($data);

        $this->validateInstallments($data);

        $invoice = DB::transaction(function () use ($data, $selectedDespatches) {
            
            $invoiceData = collect($data)->except([
                'items', 
                'installments',
                'selected_despatches',
                'number_of_installments',
                'is_credit_payment',
                'detraction_service_name',
                'detraction_payment_method_name',
            ])->toArray();
            
            $invoiceData = $this->ensureRequiredFields($invoiceData);
            
            $invoice = Invoice::create($invoiceData);

            // Crear los items
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $invoice->items()->create($itemData);
                }
                Log::info('Items creados:', ['count' => count($data['items'])]);
            }

            // Crear las cuotas si es factura a crédito
            if (isset($data['installments']) && is_array($data['installments'])) {
                foreach ($data['installments'] as $installmentData) {
                    $invoice->installments()->create($installmentData);
                }
                Log::info('Cuotas creadas:', [
                    'count' => count($data['installments']),
                    'fechas' => array_column($data['installments'], 'due_date')
                ]);
            }

            // Relacionar guías (si las hay)
            if (!empty($selectedDespatches)) {
                $invoice->despatches()->attach($selectedDespatches);
                Log::info('Guías relacionadas:', ['count' => count($selectedDespatches)]);
            }

            return $invoice->refresh();
        });

        Log::info('Factura creada exitosamente:', [
            'id' => $invoice->id,
            'series' => $invoice->series,
            'number' => $invoice->number,
            'total' => $invoice->total
        ]);

        return $invoice;
    }

    /**
     * Maneja la creación o actualización del cliente
     */
    protected function handleClientCreation(array $data): Client
    {
        Log::info('Procesando cliente:', [
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
        
        Log::info('=== INICIO CÁLCULO DE TOTALES ===', [
            'items_count' => count($items),
            'global_discount' => $globalDiscount,
            'items_data' => $items
        ]);
        
        $totalTaxable = 0;
        $totalUnaffected = 0;
        $totalExonerated = 0;
        $totalIgv = 0;
        $totalItems = 0;
        $totalItemsDiscount = 0;

        foreach ($items as $index => $item) {
            $itemTotal = (float) ($item['total'] ?? 0);
            $itemSubtotal = (float) ($item['subtotal'] ?? 0);
            $itemIgv = (float) ($item['igv'] ?? 0);
            $itemDiscount = (float) ($item['discount'] ?? 0);
            $itemIgvType = $item['igv_type'] ?? '1';
            
            Log::info('Item ' . ($index + 1), [
                'total' => $itemTotal,
                'subtotal' => $itemSubtotal,
                'igv' => $itemIgv,
                'discount' => $itemDiscount,
                'igv_type' => $itemIgvType
            ]);

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

        Log::info('=== TOTALES FINALES CALCULADOS ===', [
            'total_items_bruto' => $totalItems,
            'global_discount' => $globalDiscount,
            'total_final' => $data['total'],
            'total_taxable' => $data['total_taxable'],
            'total_igv' => $data['total_igv'],
            'todos_los_totales' => [
                'total_taxable' => $data['total_taxable'],
                'total_unaffected' => $data['total_unaffected'],
                'total_exonerated' => $data['total_exonerated'],
                'total_igv' => $data['total_igv'],
                'total_discount' => $data['total_discount'],
                'total' => $data['total']
            ]
        ]);
    }

    /**
     * Asegura que todos los campos requeridos estén presentes
     */
    protected function ensureRequiredFields(array $data): array
    {
        $defaults = [
            'total_advance' => 0.00,
            'total_gratuitous' => 0.00,
            'total_other_charges' => 0.00,
            'perception_type' => null,
            'perception_taxable_base' => 0.00,
            'total_perception' => 0.00,
            'total_included_perception' => 0.00,
            'detraction' => $data['detraction'] ?? false,
            'send_automatically_to_sunat' => true,
            'send_automatically_to_client' => false,
            'exchange_rate' => null,
            
            'detraction_service_code' => $data['detraction_service_code'] ?? '027',
            'detraction_payment_method' => $data['detraction_payment_method'] ?? '001',
            'detraction_percentage' => $data['detraction_percentage'] ?? 4.00,
            'detraction_bank_account' => $data['detraction_bank_account'] ?? null,
        ];

        foreach ($defaults as $key => $defaultValue) {
            if (!isset($data[$key])) {
                $data[$key] = $defaultValue;
            }
        }

        return $data;
    }

    /**
     * Valida que las cuotas sean correctas para facturas a crédito
     * CRITICAL: Fechas deben ser POSTERIORES a emission_date para cumplir SUNAT 3267
     */
    protected function validateInstallments(array &$data): void
    {
        Log::info('=== VALIDACIÓN DE CUOTAS PARA ERROR SUNAT 3267 ===', [
            'due_date' => $data['due_date'] ?? 'NO DEFINIDO',
            'installments_count' => count($data['installments'] ?? []),
            'emission_date' => $data['emission_date'] ?? 'NO DEFINIDO'
        ]);
        
        // Solo validar si es factura a crédito
        if (empty($data['due_date'])) {
            Log::info('Factura de CONTADO detectada - sin validación de cuotas');
            return;
        }
        
        $installments = $data['installments'] ?? [];
        $invoiceTotal = (float) ($data['total'] ?? 0);
        $emissionDate = \Carbon\Carbon::parse($data['emission_date']);
        $totalInstallments = 0; // Definir variable al inicio para evitar scope issues
        
        // Si no hay cuotas, generar una por defecto con fecha VÁLIDA
        if (empty($installments) && $invoiceTotal > 0) {
            // CRITICAL FIX: Asegurar que la fecha sea POSTERIOR a emission_date
            // Para facturas de contado convertidas a crédito, usar +7 días (estándar Greenter)
            $validDueDate = $emissionDate->copy()->addDays(7);
            
            $data['installments'] = [
                [
                    'installment_number' => 'Cuota001',
                    'amount' => $invoiceTotal,
                    'due_date' => $validDueDate->format('Y-m-d'),
                    'order' => 1,
                ]
            ];
            
            // Actualizar due_date principal con la fecha válida
            $data['due_date'] = $validDueDate->format('Y-m-d');
            
            Log::info('Generada cuota automática con fecha válida para SUNAT:', [
                'emission_date' => $emissionDate->format('Y-m-d'),
                'due_date' => $validDueDate->format('Y-m-d'),
                'amount' => $invoiceTotal
            ]);
            return;
        }
        
        // Validar que todas las fechas sean POSTERIORES a emission_date
        foreach ($installments as $index => &$installment) {
            $dueDate = \Carbon\Carbon::parse($installment['due_date']);
            
            Log::info('Validando cuota ' . ($index + 1), [
                'due_date_string' => $installment['due_date'],
                'due_date_parsed' => $dueDate->format('Y-m-d H:i:s'),
                'emission_date_parsed' => $emissionDate->format('Y-m-d H:i:s'),
                'es_posterior' => $dueDate->gt($emissionDate)
            ]);
            
            
            if ($dueDate->lte($emissionDate)) {
                // AUTO-CORREGIR fecha inválida agregando días suficientes
                $correctedDate = $emissionDate->copy()->addDays(($index + 1) * 7); // +7, +14, +21 días según cuota
                $installment['due_date'] = $correctedDate->format('Y-m-d');
                
                Log::warning('FECHA CORREGIDA AUTOMÁTICAMENTE - SUNAT 3267:', [
                    'cuota' => $index + 1,
                    'fecha_original' => $dueDate->format('Y-m-d'),
                    'fecha_corregida' => $correctedDate->format('Y-m-d'),
                    'emission_date' => $emissionDate->format('Y-m-d'),
                    'razon' => 'Fecha era igual o anterior a emisión - corregida automáticamente'
                ]);
                
                // Actualizar la variable local para continuar validaciones
                $dueDate = $correctedDate;
            }
            
            // Asegurar que tenga número de cuota y orden
            if (empty($installment['installment_number'])) {
                $installment['installment_number'] = 'Cuota' . str_pad($installment['order'] ?? ($index + 1), 3, '0', STR_PAD_LEFT);
            }
            
            if (empty($installment['order'])) {
                $installment['order'] = $index + 1;
            }
        }
        
        // Validar que la suma de cuotas coincida con el total (solo si el total > 0)
        if ($invoiceTotal > 0) {
            // Calcular total de cuotas
            foreach ($installments as $installment) {
                $totalInstallments += (float) ($installment['amount'] ?? 0);
            }
            
            $difference = abs($totalInstallments - $invoiceTotal);
            if ($difference > 0.01) {
                throw new \Exception(
                    "La suma de las cuotas (S/ " . number_format($totalInstallments, 2) . 
                    ") no coincide con el total de la factura (S/ " . number_format($invoiceTotal, 2) . "). " .
                    "Diferencia: S/ " . number_format($difference, 2)
                );
            }
            
            Log::info('Validación de montos exitosa:', [
                'total_cuotas' => $totalInstallments,
                'total_factura' => $invoiceTotal,
                'diferencia' => $difference
            ]);
        } else {
            Log::warning('Omitiendo validación de montos - total de factura es 0.00');
        }
        
        Log::info('Cuotas validadas correctamente para SUNAT:', [
            'total_cuotas' => $totalInstallments,
            'total_factura' => $invoiceTotal,
            'num_cuotas' => count($installments),
            'emission_date' => $emissionDate->format('Y-m-d'),
            'fechas_validas' => array_map(fn($i) => $i['due_date'], $installments)
        ]);
    }

    protected function afterCreate(): void
    {
        Log::info('=== POST-CREACIÓN DE FACTURA ===', [
            'invoice_id' => $this->record->id
        ]);

        // Recargar la factura con sus relaciones
        $this->record->load(['items', 'client', 'despatches']);

        // Enviar a Nubefact OSE
        $this->sendToNubefact();

        // Mostrar notificación
        $this->showSuccessNotification();
    }

    /**
     * Envía la factura a Nubefact OSE
     */
    protected function sendToNubefact(): void
    {
        try {
            Log::info('Iniciando envío a Nubefact OSE:', [
                'invoice_id' => $this->record->id,
                'series' => $this->record->series,
                'number' => $this->record->number
            ]);

            $invoiceService = new InvoiceService();
            
            // Enviar a Nubefact usando Laravel Greenter
            $result = $invoiceService->sendToNubefact($this->record);

            Log::info('Resultado del envío a Nubefact:', $result);

            if ($result['success']) {
                Log::info('Factura enviada exitosamente a Nubefact OSE', [
                    'invoice_id' => $this->record->id,
                    'sunat_accepted' => $result['sunat_accepted'] ?? null,
                    'sunat_response_code' => $result['sunat_response_code'] ?? null
                ]);
            } else {
                Log::error('Error en el envío a Nubefact OSE:', [
                    'invoice_id' => $this->record->id,
                    'error' => $result['error'] ?? 'Error desconocido'
                ]);

                Notification::make()
                    ->title('Error al enviar a Nubefact')
                    ->body($result['error'] ?? 'Error desconocido al procesar la factura')
                    ->danger()
                    ->persistent()
                    ->send();
            }

        } catch (\Exception $e) {
            Log::error('Excepción enviando factura a Nubefact:', [
                'invoice_id' => $this->record->id,
                'exception' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            Notification::make()
                ->title('Error crítico al enviar a Nubefact')
                ->body('Excepción: ' . $e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            // Marcar como error en la factura
            $this->record->update([
                'sunat_accepted' => false,
                'sunat_description' => 'Error del sistema: ' . $e->getMessage(),
            ]);
        }
    }

    protected function showSuccessNotification(): void
    {
        $this->record->refresh();

        $title = 'Factura Creada y Enviada';
        $body = "Factura {$this->record->series}-{$this->record->number} ";
        
        // Estado del envío
        if ($this->record->sunat_accepted === true) {
            $body .= 'ACEPTADA por SUNAT/Nubefact';
            $color = 'success';
        } elseif ($this->record->sunat_accepted === false) {
            $body .= 'RECHAZADA por SUNAT/Nubefact';
            $color = 'danger';
        } else {
            $body .= 'Enviada a Nubefact OSE (procesando...)';
            $color = 'info';
        }

        // Información del total
        $body .= " | Total: S/ " . number_format($this->record->total, 2);

        // Información de detracción
        if ($this->record->detraction) {
            $detractionAmount = $this->record->total * ($this->record->detraction_percentage / 100);
            $netPayable = $this->record->total - $detractionAmount;
            $body .= " | Detracción: S/ " . number_format($detractionAmount, 2);
            $body .= " | Neto: S/ " . number_format($netPayable, 2);
        }

        // Información de pago
        if ($this->record->due_date) {
            $daysUntilDue = now()->diffInDays($this->record->due_date, false);
            $body .= " | Crédito a {$daysUntilDue} días";
        } else {
            $body .= " | Contado";
        }

        // Guías relacionadas
        if ($this->record->despatches->isNotEmpty()) {
            $despatchCount = $this->record->despatches->count();
            $body .= " | {$despatchCount} GRE(s) adjuntas";
        }

        Notification::make()
            ->title($title)
            ->body($body)
            ->color($color ?? 'info')
            ->duration(8000)
            ->actions([
                \Filament\Notifications\Actions\Action::make('view')
                    ->label('Ver Factura')
                    ->url(InvoiceResource::getUrl('index'))
                    ->button(),
                \Filament\Notifications\Actions\Action::make('nubefact')
                    ->label(' Panel Nubefact')
                    ->url('https://demo.nubefact.com/login')
                    ->openUrlInNewTab()
                    ->button(),
            ])
            ->send();

        // Log de éxito
        Log::info('=== FACTURA COMPLETADA ===', [
            'invoice_id' => $this->record->id,
            'series' => $this->record->series,
            'number' => $this->record->number,
            'total' => $this->record->total,
            'sunat_accepted' => $this->record->sunat_accepted,
            'sunat_response_code' => $this->record->sunat_response_code,
            'detraction' => $this->record->detraction,
            'payment_type' => $this->record->due_date ? 'Crédito' : 'Contado',
            'despatches_count' => $this->record->despatches->count(),
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // DEBUG: Logging detallado de los datos recibidos
        Log::info('=== DEBUGGING mutateFormDataBeforeCreate ===', [
            'all_data' => $data,
            'series_exists' => array_key_exists('series', $data),
            'series_value' => $data['series'] ?? 'NO_EXISTE',
            'series_empty' => empty($data['series']),
            'number_exists' => array_key_exists('number', $data),
            'number_value' => $data['number'] ?? 'NO_EXISTE',
            'number_empty' => empty($data['number']),
            'data_keys' => array_keys($data)
        ]);

        // SOLUCIÓN ROBUSTA: Asegurar que serie y número siempre estén presentes
        if (empty($data['series'])) {
            $data['series'] = 'F001'; // Serie por defecto
            Log::info('Serie no presente o vacía, asignando por defecto: F001');
        }
        
        if (empty($data['number'])) {
            $data['number'] = InvoiceResource::getNextCorrelativeNumber($data['series']);
            Log::info('Número no presente o vacío, generando automáticamente:', [
                'series' => $data['series'],
                'generated_number' => $data['number']
            ]);
        }

        // Validaciones básicas después de asegurar valores
        if (empty($data['series']) || empty($data['number'])) {
            Log::error('Falló validación de serie y número DESPUÉS de generación automática:', [
                'series' => $data['series'] ?? 'NO_EXISTE',
                'number' => $data['number'] ?? 'NO_EXISTE',
                'series_empty' => empty($data['series']),
                'number_empty' => empty($data['number'])
            ]);
            throw new \Exception('Error crítico: No se pudo generar serie y número automáticamente.');
        }

        if (empty($data['client_name']) || empty($data['client_document_number'])) {
            throw new \Exception('Datos del cliente son requeridos.');
        }

        if (empty($data['items']) || !is_array($data['items'])) {
            throw new \Exception('Debe incluir al menos un item en la factura.');
        }

        // Verificar unicidad de serie-número
        $existingInvoice = Invoice::where('series', $data['series'])
            ->where('number', $data['number'])
            ->first();

        if ($existingInvoice) {
            throw new \Exception("Ya existe una factura con serie {$data['series']} y número {$data['number']}.");
        }

        
        if (isset($data['is_credit_payment']) && !$data['is_credit_payment']) {
            $data['due_date'] = null; // Si no es crédito, limpiar fecha de vencimiento
        }
        
        

        Log::info('Datos validados antes de crear:', [
            'series' => $data['series'],
            'number' => $data['number'],
            'total' => $data['total'] ?? 'N/A',
            'client_name' => $data['client_name'] ?? 'N/A',
            'items_count' => count($data['items'] ?? []),
            'detraction' => $data['detraction'] ?? false,
            'due_date' => $data['due_date'] ?? null,
            'installments_count' => count($data['installments'] ?? []),
        ]);

        return $data;
    }
}