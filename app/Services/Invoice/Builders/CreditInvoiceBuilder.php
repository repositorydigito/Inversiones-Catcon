<?php

namespace App\Services\Invoice\Builders;


class CreditInvoiceBuilder extends AbstractInvoiceBuilder
{
    
    public function buildPaymentTerms(): array
    {
        return [
            "formaPago" => [
                'tipo' => 'Credito',
                'monto' => (float) $this->invoice->total,
            ],
            "cuotas" => $this->buildInstallments(),
        ];
    }
    
    
    public function buildSpecificData(): array
    {
      
        $installments = $this->buildInstallments();
        
        // La fecha de vencimiento principal debe ser la fecha de la última cuota
        $lastInstallmentDate = null;
        if (!empty($installments)) {
            $lastInstallment = end($installments);
            $lastInstallmentDate = $lastInstallment['fechaPago']; // FIXED: fechaPago
        }
        
        $specificData = $this->buildTotalsData();
        
        // Solo agregar fechaVencimiento si hay cuotas y es diferente a la base
        if ($lastInstallmentDate) {
            $specificData['fechaVencimiento'] = $lastInstallmentDate;
        }
        
        return $specificData;
    }
    

    protected function buildInstallments(): array
    {
        // Cargar cuotas si no están cargadas
        if (!$this->invoice->relationLoaded('installments')) {
            $this->invoice->load('installments');
        }
        
        // Si existen cuotas configuradas, validarlas y usarlas
        if ($this->invoice->installments->count() > 0) {
            return $this->invoice->installments->map(function ($installment) {
                // CRITICAL VALIDATION: Asegurar que la fecha es POSTERIOR a emission_date
                $dueDate = $installment->due_date;
                $emissionDate = $this->invoice->emission_date;
                
                // Si la fecha de cuota no es posterior a emisión, corregirla automáticamente
                if ($dueDate->lte($emissionDate)) {
                    $correctedDate = $emissionDate->copy()->addDays(7); // Mínimo +7 días
                    
                    \Log::warning('FECHA DE CUOTA CORREGIDA AUTOMÁTICAMENTE EN BUILDER:', [
                        'invoice_id' => $this->invoice->id,
                        'installment_id' => $installment->id,
                        'original_date' => $dueDate->format('Y-m-d'),
                        'corrected_date' => $correctedDate->format('Y-m-d'),
                        'emission_date' => $emissionDate->format('Y-m-d'),
                        'reason' => 'SUNAT 3267 Prevention'
                    ]);
                    
                    $dueDate = $correctedDate;
                }
                
                return [
                    "cuota" => $installment->installment_number,
                    "fechaPago" => $dueDate->format('Y-m-d'), // FIXED: fechaPago no fechaVencimiento
                    "monto" => (float) $installment->amount
                ];
            })->toArray();
        }
        
        // Fallback: Generar una sola cuota con el total (facturas creadas antes del sistema de cuotas)
        // CRITICAL: Asegurar fecha válida para SUNAT 3267
        $validDueDate = $this->invoice->due_date;
        
        // ALWAYS validate: Si la fecha de vencimiento es igual o anterior a la emisión, corregirla
        if ($validDueDate->lte($this->invoice->emission_date)) {
            $validDueDate = $this->invoice->emission_date->copy()->addDays(7);
            
            \Log::warning('FECHA DE VENCIMIENTO CORREGIDA AUTOMÁTICAMENTE EN BUILDER:', [
                'invoice_id' => $this->invoice->id,
                'original_date' => $this->invoice->due_date->format('Y-m-d'),
                'corrected_date' => $validDueDate->format('Y-m-d'),
                'emission_date' => $this->invoice->emission_date->format('Y-m-d'),
                'reason' => 'SUNAT 3267 Prevention - Fallback'
            ]);
        }
        
        return [
            [
                "cuota" => "Cuota001",
                "fechaPago" => $validDueDate->format('Y-m-d'), // FIXED: fechaPago no fechaVencimiento
                "monto" => (float) $this->invoice->total
            ]
        ];
    }
}