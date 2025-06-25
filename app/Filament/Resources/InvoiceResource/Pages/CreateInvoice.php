<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Models\Invoice; 
use App\Models\Client;
use App\Models\MeasureUnit; 
use App\Services\NubefactService; 
use Filament\Notifications\Notification; 
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;
    
    protected function handleRecordCreation(array $data): Invoice
    {
        //dd('Datos iniciales del formulario:', $data);
        $client = Client::firstOrCreate(
            ['document_number' => $data['client_document_number']],
            [
                'name' => $data['client_name'],
                'document_type' => $data['client_document_type'],
                'address' => $data['client_address'] ?? null,
                'email' => $data['client_email'] ?? null,
            ]
        );
        $data['client_id'] = $client->id;
        
        $calculatedTotalTaxable = 0;    
        $calculatedTotalUnaffected = 0; 
        $calculatedTotalExonerated = 0;
        $calculatedIgv = 0; 
        $calculatedTotalIgv = 0;       
        $calculatedTotalItems = 0;    
        $calculatedItemsDiscount = 0;   

        $globalDiscount = (float) ($data['global_discount'] ?? 0.00); 

        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $index => $itemData) {
                $quantity = (float) ($itemData['quantity'] ?? 0.00);
                $unitValue = (float) ($itemData['unit_value'] ?? 0.00); 
                $unitPrice = (float) ($itemData['unit_price'] ?? 0.00); 
                $discount = (float) ($itemData['discount'] ?? 0.00);
                $igvType = $itemData['igv_type'] ?? '1';
                $globalIgvPercentage = (float) ($data['igv_percentage'] ?? 0.00);

                $itemSubtotal = ($quantity * $unitValue) - $discount;
                $itemIgv = 0;
                $itemTotal = ($quantity * $unitPrice) - $discount;

                if ($igvType === '1' && $globalIgvPercentage > 0) {
                    $itemIgv = $itemTotal - $itemSubtotal;
                }

                $data['items'][$index]['subtotal'] = round($itemSubtotal, 2);
                $data['items'][$index]['igv'] = round($itemIgv, 2);
                $data['items'][$index]['total'] = round($itemTotal, 2);

                $calculatedTotalItems += $itemTotal;
                $calculatedIgv += $itemIgv;
                $calculatedItemsDiscount += $discount; 

                switch ($igvType) {
                    case '1': 
                        $calculatedTotalTaxable += $itemSubtotal;
                        break;
                    case '8': 
                        $calculatedTotalExonerated += $itemSubtotal;
                        break;
                    case '9': 
                        $calculatedTotalUnaffected += $itemSubtotal;
                        break;
                }
            }
        }

        $data['total_taxable'] = round($calculatedTotalTaxable, 2);
        $data['total_unaffected'] = round($calculatedTotalUnaffected, 2);
        $data['total_exonerated'] = round($calculatedTotalExonerated, 2);
        $data['total_igv'] = round($calculatedIgv, 2);
        $data['total_discount'] = round($globalDiscount + $calculatedItemsDiscount, 2); 
        $data['total'] = round($calculatedTotalItems - $globalDiscount, 2); 
        $data['total_advance'] = (float) ($data['total_advance'] ?? 0.00);
        $data['total_gratuitous'] = (float) ($data['total_gratuitous'] ?? 0.00);
        $data['total_other_charges'] = (float) ($data['total_other_charges'] ?? 0.00);
        $data['perception_taxable_base'] = (float) ($data['perception_taxable_base'] ?? 0.00);
        $data['total_perception'] = (float) ($data['total_perception'] ?? 0.00);
        $data['total_included_perception'] = (float) ($data['total_included_perception'] ?? 0.00);

        $invoice = DB::transaction(function () use ($data) {

            $invoiceData = collect($data)->except('items')->toArray();
            $invoice = Invoice::create($invoiceData);

            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $itemData) {                    
                    $invoice->items()->create($itemData);
                }
            }            

            $invoice->refresh();
            return $invoice;
        });        
        
        return $invoice;              
    }

    protected function afterCreate(): void
    {
        $this->record->load(['items', 'client']);

        $igvPercentage = (float) ($this->record->igv_percentage ?? 0.00);
        $globalDiscount = (float) ($this->record->global_discount ?? 0.00);

        $totalTaxable = 0;
        $totalUnaffected = 0;
        $totalExonerated = 0;
        $totalIgv = 0;
        $totalItems = 0;
        $itemsDiscount = 0;
        $totalAdvance = (float) ($this->record->total_advance ?? 0.00);
        $totalGratuitous = (float) ($this->record->total_gratuitous ?? 0.00);
        $totalOtherCharges = (float) ($this->record->total_other_charges ?? 0.00);
        $perceptionTaxableBase = (float) ($this->record->perception_taxable_base ?? 0.00);
        $totalPerception = (float) ($this->record->total_perception ?? 0.00);
        $totalIncludedPerception = (float) ($this->record->total_included_perception ?? 0.00);

        // Recalcula y actualiza cada ítem
        foreach ($this->record->items as $item) {
            $quantity = (float) ($item->quantity ?? 0.00);
            $unitValue = (float) ($item->unit_value ?? 0.00); // sin IGV
            $unitPrice = (float) ($item->unit_price ?? 0.00); // con IGV
            $discount = (float) ($item->discount ?? 0.00);
            $igvType = $item->igv_type ?? '1';

            $itemSubtotal = ($quantity * $unitValue) - $discount;
            $itemTotal = ($quantity * $unitPrice) - $discount;
            $itemIgv = 0;

            if ($igvType === '1' && $igvPercentage > 0) {
                $itemIgv = $itemTotal - $itemSubtotal;
            }

            // Acumula para la factura
            $totalItems += $itemTotal;
            $totalIgv += $itemIgv;
            $itemsDiscount += $discount;

            switch ($igvType) {
                case '1': // Gravado
                    $totalTaxable += $itemSubtotal;
                    break;
                case '8': // Exonerado
                    $totalExonerated += $itemSubtotal;
                    break;
                case '9': // Inafecto
                    $totalUnaffected += $itemSubtotal;
                    break;
            }

            // Actualiza los campos del ítem en la BD
            $item->subtotal = round($itemSubtotal, 2);
            $item->igv = round($itemIgv, 2);
            $item->total = round($itemTotal, 2);
            $item->save();
        }

        // Calcula los totales de la factura
        $this->record->total_taxable = round($totalTaxable, 2);
        $this->record->total_unaffected = round($totalUnaffected, 2);
        $this->record->total_exonerated = round($totalExonerated, 2);
        $this->record->total_igv = round($totalIgv, 2);
        $this->record->total_discount = round($globalDiscount + $itemsDiscount, 2);
        $this->record->total = round($totalItems - $globalDiscount, 2);
        $this->record->total_advance = $totalAdvance;
        $this->record->total_gratuitous = $totalGratuitous;
        $this->record->total_other_charges = $totalOtherCharges;
        $this->record->perception_taxable_base = $perceptionTaxableBase;
        $this->record->total_perception = $totalPerception;
        $this->record->total_included_perception = $totalIncludedPerception;
        $this->record->save();

        // Aquí puedes llamar a NubefactService
        try {
            $nubefactService = new \App\Services\NubefactService();
            $payload = $nubefactService->buildInvoicePayload($this->record);
            $response = $nubefactService->sendInvoice($payload);
        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('Error al enviar a Nubefact')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
