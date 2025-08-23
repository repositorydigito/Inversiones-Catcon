<?php

namespace App\Services\Invoice\Builders;


class DetractionInvoiceBuilder extends AbstractInvoiceBuilder
{
    
    public function buildPaymentTerms(): array
    {
        return [
            "formaPago" => [
                'tipo' => 'Contado',
            ],
        ];
    }
    
    
    public function buildSpecificData(): array
    {
        return array_merge($this->buildTotalsData(), [
            "detraccion" => $this->buildDetractionData(),
            "tipoOperacion" => "1001", // Operación sujeta a detracción
        ]);
    }
    
    
    protected function buildLegends(): array
    {
        $legends = parent::buildLegends();
        
        
        $legends[] = [
            "code" => "2006",
            "value" => "Operación sujeta a detracción"
        ];
        
        return $legends;
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
        

        if (!empty($this->invoice->detraction_bank_account)) {
            $data["ctaBanco"] = $this->invoice->detraction_bank_account;
        }
        
        return $data;
    }
}