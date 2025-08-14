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
        Log::info('=== INICIANDO CREACIÓN DE FACTURA ===', [
            'series' => $data['series'] ?? 'N/A',
            'number' => $data['number'] ?? 'N/A'
        ]);

        // Crear o actualizar cliente
        $client = $this->handleClientCreation($data);
        $data['client_id'] = $client->id;

        // Procesar guías seleccionadas (opcional)
        $selectedDespatches = $data['selected_despatches'] ?? [];

        // Calcular totales finales
        $this->calculateFinalTotals($data);

        // Crear la factura en una transacción
        $invoice = DB::transaction(function () use ($data, $selectedDespatches) {
            
            // Limpiar campos que no van en la tabla
            $invoiceData = collect($data)->except([
                'items', 
                'selected_despatches',
                'is_credit_payment', // Campo auxiliar del form
                'detraction_service_name', // Campo auxiliar
                'detraction_payment_method_name', // Campo auxiliar
            ])->toArray();
            
            // Asegurar campos requeridos
            $invoiceData = $this->ensureRequiredFields($invoiceData);
            
            Log::info('Creando factura con datos:', array_keys($invoiceData));
            
            // Crear la factura
            $invoice = Invoice::create($invoiceData);

            // Crear los items
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $invoice->items()->create($itemData);
                }
                Log::info('Items creados:', ['count' => count($data['items'])]);
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

        Log::info('Totales calculados:', [
            'total_taxable' => $data['total_taxable'],
            'total_igv' => $data['total_igv'],
            'total' => $data['total']
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
            // Campos de detracción con valores por defecto
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
        // Validaciones básicas antes de crear
        if (empty($data['series']) || empty($data['number'])) {
            throw new \Exception('Serie y número son requeridos.');
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

        // Mapear el campo auxiliar is_credit_payment
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
        ]);

        return $data;
    }
}