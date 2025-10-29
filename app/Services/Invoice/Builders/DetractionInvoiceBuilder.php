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
        // ACTUALIZADO: Usar el total_detraction calculado en lugar de calcular manualmente
        $detractionAmount = (float) $this->invoice->total_detraction;

        // Validar que la cuenta bancaria esté presente
        if (empty($this->invoice->detraction_bank_account)) {
            throw new \Exception('La cuenta bancaria de detracción es obligatoria para facturas con detracción.');
        }

        $data = [
            "codBienDetraccion" => $this->invoice->detraction_service_code,
            "codMedioPago" => $this->invoice->detraction_payment_method,
            "percent" => (float) $this->invoice->detraction_percentage,
            "mount" => round($detractionAmount, 2), // Usar monto calculado desde items
            "ctaBanco" => $this->invoice->detraction_bank_account
        ];

        \Log::info('Datos de detracción construidos:', [
            'invoice_id' => $this->invoice->id,
            'detraction_amount' => $detractionAmount,
            'percentage' => $this->invoice->detraction_percentage,
            'service_code' => $this->invoice->detraction_service_code
        ]);

        return $data;
    }
}
