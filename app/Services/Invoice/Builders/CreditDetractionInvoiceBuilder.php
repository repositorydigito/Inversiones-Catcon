<?php

namespace App\Services\Invoice\Builders;



class CreditDetractionInvoiceBuilder extends AbstractInvoiceBuilder
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
        
        
        $lastInstallmentDate = null;
        if (!empty($installments)) {
            $lastInstallment = end($installments);
            $lastInstallmentDate = $lastInstallment['fechaPago']; // 
        }
        
        $specificData = array_merge($this->buildTotalsData(), [
            "detraccion" => $this->buildDetractionData(),
            "tipoOperacion" => "1001", // Operación sujeta a detracción
        ]);
        
        // Solo agregar fechaVencimiento si hay cuotas y es diferente a la base
        if ($lastInstallmentDate) {
            $specificData['fechaVencimiento'] = $lastInstallmentDate;
        }
        
        return $specificData;
    }
    
    /**
     * Sobrescribe las leyendas para incluir leyenda de detracción
     */
    protected function buildLegends(): array
    {
        $legends = parent::buildLegends();
        
        // Agregar leyenda específica de detracción
        $legends[] = [
            "code" => "2006",
            "value" => "Operación sujeta a detracción"
        ];
        
        return $legends;
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
                    
                    \Log::warning('FECHA DE CUOTA CORREGIDA AUTOMÁTICAMENTE EN BUILDER DETRACCIÓN:', [
                        'invoice_id' => $this->invoice->id,
                        'installment_id' => $installment->id,
                        'original_date' => $dueDate->format('Y-m-d'),
                        'corrected_date' => $correctedDate->format('Y-m-d'),
                        'emission_date' => $emissionDate->format('Y-m-d'),
                        'reason' => 'SUNAT 3267 Prevention with Detraction'
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
        
      
        $validDueDate = $this->invoice->due_date;
        
     
        if ($validDueDate->lte($this->invoice->emission_date)) {
            $validDueDate = $this->invoice->emission_date->copy()->addDays(7);
            
            \Log::warning('FECHA DE VENCIMIENTO CORREGIDA AUTOMÁTICAMENTE EN BUILDER DETRACCIÓN:', [
                'invoice_id' => $this->invoice->id,
                'original_date' => $this->invoice->due_date->format('Y-m-d'),
                'corrected_date' => $validDueDate->format('Y-m-d'),
                'emission_date' => $this->invoice->emission_date->format('Y-m-d'),
                'reason' => 'SUNAT 3267 Prevention - Fallback with Detraction'
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
    
  
    protected function buildDetractionData(): array
    {
        $detractionAmount = $this->invoice->total * ($this->invoice->detraction_percentage / 100);
        
        $data = [
            "codBienDetraccion" => $this->invoice->detraction_service_code,
            "codMedioPago" => $this->invoice->detraction_payment_method,
            "percent" => (float) $this->invoice->detraction_percentage,
            "mount" => round($detractionAmount, 2)
        ];
        
        // Solo agregar cuenta bancaria si tiene valor válido
        if (!empty($this->invoice->detraction_bank_account)) {
            $data["ctaBanco"] = $this->invoice->detraction_bank_account;
        }
        
        return $data;
    }
}