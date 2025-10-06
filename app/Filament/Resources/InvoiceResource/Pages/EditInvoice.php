<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Models\Client;
use App\Models\Invoice;
use Carbon\Carbon;
use Filament\Actions;
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
        
        // Validar cuotas
        $this->validateInstallments($data);
        
        // Actualizar en transacción
        return DB::transaction(function () use ($record, $data) {
            // Preparar datos de la factura
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
                    // Eliminar ID si existe para forzar creación nueva
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
     * Métodos helper para mantener consistencia con CreateInvoice
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
            
            // CRITICAL: Fecha debe ser POSTERIOR a emission_date
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