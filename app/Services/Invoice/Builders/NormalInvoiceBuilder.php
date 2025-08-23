<?php

namespace App\Services\Invoice\Builders;


class NormalInvoiceBuilder extends AbstractInvoiceBuilder
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
        
        ]);
    }
}