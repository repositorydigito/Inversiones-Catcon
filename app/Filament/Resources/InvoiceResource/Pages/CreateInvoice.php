<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\Client;
use App\Models\MeasureUnit;
use App\Models\Despatch;
use App\Services\NubefactService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function handleRecordCreation(array $data): Invoice
    {
        // Obtener las guías seleccionadas
        $selectedDespatches = $data['selected_despatches'] ?? [];
        
        // Verificar que el cliente sea consistente en todas las guías
        if (!empty($selectedDespatches)) {
            $this->validateDespatchesClient($selectedDespatches);
        }

        // Crear o actualizar cliente si es necesario
        $client = $this->handleClientCreation($data);
        $data['client_id'] = $client->id;

        // Calcular totales de items
        $this->calculateItemTotals($data);

        // Crear la factura en una transacción
        $invoice = DB::transaction(function () use ($data, $selectedDespatches) {
            // Preparar datos de la factura (excluir campos que no van en la tabla)
            $invoiceData = collect($data)->except(['items', 'selected_despatches', 'manual_mode'])->toArray();
            
            // Asegurar que todos los campos requeridos estén presentes
            $invoiceData = $this->ensureRequiredFields($invoiceData);
            
            // Crear la factura
            $invoice = Invoice::create($invoiceData);

            // Crear los items
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $invoice->items()->create($itemData);
                }
            }

            // Relacionar con las guías de remisión
            if (!empty($selectedDespatches)) {
                $invoice->despatches()->attach($selectedDespatches);
            }

            $invoice->refresh();
            return $invoice;
        });

        return $invoice;
    }

    /**
     * Valida que todas las guías pertenezcan al mismo cliente
     */
    protected function validateDespatchesClient(array $despatchIds): void
    {
        $clients = Despatch::whereIn('id', $despatchIds)
            ->distinct()
            ->pluck('client_id')
            ->toArray();

        if (count($clients) > 1) {
            throw new \Exception('Todas las guías de remisión deben pertenecer al mismo cliente.');
        }

        // Verificar que las guías estén disponibles para facturar
        $unavailableDespatches = Despatch::whereIn('id', $despatchIds)
            ->where(function ($query) {
                $query->where('accepted_by_sunat', '!=', true)
                    ->orWhereHas('invoices');
            })
            ->count();

        if ($unavailableDespatches > 0) {
            throw new \Exception('Alguna de las guías seleccionadas no está disponible para facturar (no aceptada por SUNAT o ya facturada).');
        }
    }

    /**
     * Maneja la creación o actualización del cliente
     */
    protected function handleClientCreation(array $data): Client
    {
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
     * Calcula los totales de los items y de la factura
     */
    protected function calculateItemTotals(array &$data): void
    {
        $calculatedTotalTaxable = 0;
        $calculatedTotalUnaffected = 0;
        $calculatedTotalExonerated = 0;
        $calculatedTotalIgv = 0;
        $calculatedTotalItems = 0;
        $calculatedItemsDiscount = 0;

        $globalDiscount = (float) ($data['global_discount'] ?? 0.00);
        $globalIgvPercentage = (float) ($data['igv_percentage'] ?? 18.00);

        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $index => $itemData) {
                $quantity = (float) ($itemData['quantity'] ?? 0.00);
                $unitValue = (float) ($itemData['unit_value'] ?? 0.00);
                $unitPrice = (float) ($itemData['unit_price'] ?? 0.00);
                $discount = (float) ($itemData['discount'] ?? 0.00);
                $igvType = $itemData['igv_type'] ?? '1';

                $itemSubtotal = ($quantity * $unitValue) - $discount;
                $itemIgv = 0;
                $itemTotal = ($quantity * $unitPrice) - $discount;

                if ($igvType === '1' && $globalIgvPercentage > 0) {
                    $itemIgv = $itemTotal - $itemSubtotal;
                }

                // Actualizar el array con los valores calculados
                $data['items'][$index]['subtotal'] = round($itemSubtotal, 2);
                $data['items'][$index]['igv'] = round($itemIgv, 2);
                $data['items'][$index]['total'] = round($itemTotal, 2);

                // Acumular totales
                $calculatedTotalItems += $itemTotal;
                $calculatedTotalIgv += $itemIgv;
                $calculatedItemsDiscount += $discount;

                switch ($igvType) {
                    case '1': // Gravado
                        $calculatedTotalTaxable += $itemSubtotal;
                        break;
                    case '8': // Exonerado
                        $calculatedTotalExonerated += $itemSubtotal;
                        break;
                    case '9': // Inafecto
                        $calculatedTotalUnaffected += $itemSubtotal;
                        break;
                }
            }
        }

        // Actualizar totales en el array de datos
        $data['total_taxable'] = round($calculatedTotalTaxable, 2);
        $data['total_unaffected'] = round($calculatedTotalUnaffected, 2);
        $data['total_exonerated'] = round($calculatedTotalExonerated, 2);
        $data['total_igv'] = round($calculatedTotalIgv, 2);
        $data['total_discount'] = round($globalDiscount + $calculatedItemsDiscount, 2);
        $data['total'] = round($calculatedTotalItems - $globalDiscount, 2);
    }

    /**
     * Asegura que todos los campos requeridos estén presentes con valores por defecto
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
            'detraction' => false,
            'send_automatically_to_sunat' => true,
            'send_automatically_to_client' => false,
            'exchange_rate' => null,
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
        // Recargar la factura con sus relaciones
        $this->record->load(['items', 'client', 'despatches']);

        // Recalcular y actualizar totales (por si acaso)
        $this->recalculateAndUpdateTotals();

        // Enviar a Nubefact
        $this->sendToNubefact();

        // Mostrar notificación de éxito
        $this->showSuccessNotification();
    }

    /**
     * Recalcula y actualiza los totales de la factura
     */
    protected function recalculateAndUpdateTotals(): void
    {
        $igvPercentage = (float) ($this->record->igv_percentage ?? 18.00);
        $globalDiscount = (float) ($this->record->global_discount ?? 0.00);

        $totalTaxable = 0;
        $totalUnaffected = 0;
        $totalExonerated = 0;
        $totalIgv = 0;
        $totalItems = 0;
        $itemsDiscount = 0;

        // Recalcular cada ítem
        foreach ($this->record->items as $item) {
            $quantity = (float) ($item->quantity ?? 0.00);
            $unitValue = (float) ($item->unit_value ?? 0.00);
            $unitPrice = (float) ($item->unit_price ?? 0.00);
            $discount = (float) ($item->discount ?? 0.00);
            $igvType = $item->igv_type ?? '1';

            $itemSubtotal = ($quantity * $unitValue) - $discount;
            $itemTotal = ($quantity * $unitPrice) - $discount;
            $itemIgv = 0;

            if ($igvType === '1' && $igvPercentage > 0) {
                $itemIgv = $itemTotal - $itemSubtotal;
            }

            // Acumular totales
            $totalItems += $itemTotal;
            $totalIgv += $itemIgv;
            $itemsDiscount += $discount;

            switch ($igvType) {
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

            // Actualizar el ítem en la BD
            $item->update([
                'subtotal' => round($itemSubtotal, 2),
                'igv' => round($itemIgv, 2),
                'total' => round($itemTotal, 2),
            ]);
        }

        // Actualizar totales de la factura
        $this->record->update([
            'total_taxable' => round($totalTaxable, 2),
            'total_unaffected' => round($totalUnaffected, 2),
            'total_exonerated' => round($totalExonerated, 2),
            'total_igv' => round($totalIgv, 2),
            'total_discount' => round($globalDiscount + $itemsDiscount, 2),
            'total' => round($totalItems - $globalDiscount, 2),
        ]);
    }

    /**
     * Envía la factura a Nubefact
     */
    protected function sendToNubefact(): void
    {
        try {
            $nubefactService = new NubefactService();
            
            // Construir el payload usando tu servicio existente
            $payload = $nubefactService->buildInvoicePayload($this->record);

            // Enviar a Nubefact usando tu servicio existente
            $response = $nubefactService->sendInvoice($payload);

            // Actualizar la factura con la respuesta de SUNAT
            $this->updateInvoiceWithSunatResponse($response);

        } catch (\Exception $e) {
            Notification::make()
                ->title('Error al enviar a Nubefact')
                ->body('Error: ' . $e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            // Log del error para debugging
            \Log::error('Error enviando factura a Nubefact: ' . $e->getMessage(), [
                'invoice_id' => $this->record->id,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Actualiza la factura con la respuesta de SUNAT
     */
    protected function updateInvoiceWithSunatResponse(array $response): void
    {
        $updateData = [];

        // Mapear campos de respuesta según la estructura de Nubefact
        if (isset($response['aceptada_por_sunat'])) {
            $updateData['sunat_accepted'] = $response['aceptada_por_sunat'];
        }

        if (isset($response['sunat_description'])) {
            $updateData['sunat_description'] = $response['sunat_description'];
        }

        if (isset($response['sunat_note'])) {
            $updateData['sunat_note'] = $response['sunat_note'];
        }

        if (isset($response['sunat_responsecode'])) {
            $updateData['sunat_response_code'] = $response['sunat_responsecode'];
        }

        if (isset($response['sunat_soap_error'])) {
            $updateData['sunat_soap_error'] = $response['sunat_soap_error'];
        }

        if (isset($response['pdf_zip_base64'])) {
            $updateData['pdf_zip_base64'] = $response['pdf_zip_base64'];
        }

        if (isset($response['xml_zip_base64'])) {
            $updateData['xml_zip_base64'] = $response['xml_zip_base64'];
        }

        if (isset($response['cdr_zip_base64'])) {
            $updateData['cdr_zip_base64'] = $response['cdr_zip_base64'];
        }

        if (isset($response['cadena_para_codigo_qr'])) {
            $updateData['qr_code_string'] = $response['cadena_para_codigo_qr'];
        }

        if (isset($response['enlace_del_pdf'])) {
            $updateData['pdf_link'] = $response['enlace_del_pdf'];
        }

        if (isset($response['enlace_del_xml'])) {
            $updateData['xml_link'] = $response['enlace_del_xml'];
        }

        if (isset($response['enlace_del_cdr'])) {
            $updateData['cdr_link'] = $response['enlace_del_cdr'];
        }

        if (isset($response['codigo_de_barras'])) {
            $updateData['barcode_string'] = $response['codigo_de_barras'];
        }        

        if (isset($response['hash'])) {
            $updateData['hash_code'] = $response['hash'];
        }

        if (isset($response['enlace_del_pdf'])) {
            $updateData['sunat_link'] = $response['enlace_del_pdf'];
        }

        if (!empty($updateData)) {
            $this->record->update($updateData);
        }
    }
    
    protected function showSuccessNotification(): void
    {
        $message = 'Factura creada exitosamente.';
        
        if ($this->record->despatches->isNotEmpty()) {
            $despatchNumbers = $this->record->despatches
                ->map(fn($d) => "GR {$d->series}-{$d->number}")
                ->join(', ');
            
            $message .= " Guías relacionadas: {$despatchNumbers}";
        }

        if ($this->record->sunat_accepted === true) {
            $message .= ' ✅ Aceptada por SUNAT.';
        } elseif ($this->record->sunat_accepted === false) {
            $message .= ' ❌ Rechazada por SUNAT.';
        }

        Notification::make()
            ->title('Factura Creada')
            ->body($message)
            ->success()
            ->duration(5000)
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }    
}