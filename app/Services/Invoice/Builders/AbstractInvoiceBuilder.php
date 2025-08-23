<?php

namespace App\Services\Invoice\Builders;

use App\Models\Invoice;

abstract class AbstractInvoiceBuilder
{
    protected Invoice $invoice;
    
    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }
    
    
    public function buildInvoiceData(): array
    {
        $baseData = $this->buildBaseStructure();
        $paymentData = $this->buildPaymentTerms();
        $specificData = $this->buildSpecificData();
        
        return array_merge($baseData, $paymentData, $specificData);
    }
    
   
    protected function buildBaseStructure(): array
    {
        $currencyMap = ['1' => 'PEN', '2' => 'USD'];
        
        $baseData = [
            "ublVersion" => "2.1",
            "tipoOperacion" => $this->invoice->transaction_type,
            "tipoDoc" => $this->invoice->invoice_type,
            "serie" => $this->invoice->series,
            "correlativo" => (string) $this->invoice->number,
            "fechaEmision" => $this->invoice->emission_date->format('Y-m-d'),
            "tipoMoneda" => $currencyMap[$this->invoice->currency] ?? 'PEN',
            "client" => $this->buildClientData(),
            "details" => $this->buildItemsData(),
            "legends" => $this->buildLegends(),
        ];
        
       
        if (!is_null($this->invoice->due_date)) {
           
            $dueDate = $this->invoice->due_date;
            $emissionDate = $this->invoice->emission_date;
            
            if ($dueDate->lte($emissionDate)) {
                $dueDate = $emissionDate->copy()->addDays(7);
                \Log::warning('FECHA DE VENCIMIENTO CORREGIDA EN BASE STRUCTURE:', [
                    'invoice_id' => $this->invoice->id,
                    'original_date' => $this->invoice->due_date->format('Y-m-d'),
                    'corrected_date' => $dueDate->format('Y-m-d'),
                    'emission_date' => $emissionDate->format('Y-m-d')
                ]);
            }
            
            $baseData["fechaVencimiento"] = $dueDate->format('Y-m-d');
        }
        
        return $baseData;
    }
    
 
    protected function buildClientData(): array
    {
        return [
            "tipoDoc" => ($this->invoice->client->document_type === 'DNI') ? '1' : '6',
            "numDoc" => $this->invoice->client->document_number,
            "rznSocial" => $this->invoice->client->name,
        ];
    }
    
    
    protected function buildItemsData(): array
    {
        $details = [];
        foreach ($this->invoice->items as $item) {
            $details[] = [
                "codProducto" => $item->code ?? '',
                "unidad" => "NIU",
                "cantidad" => (float) $item->quantity,
                "mtoValorUnitario" => (float) $item->unit_value,
                "descripcion" => $item->description,
                "mtoBaseIgv" => (float) ($item->quantity * $item->unit_value),
                "porcentajeIgv" => (float) $this->invoice->igv_percentage,
                "igv" => (float) $item->igv,
                "tipAfeIgv" => "10",
                "totalImpuestos" => (float) $item->igv,
                "mtoValorVenta" => (float) $item->subtotal,
                "mtoPrecioUnitario" => (float) $item->unit_price,
            ];
        }
        
        return $details;
    }
    
    
    protected function buildTotalsData(): array
    {
        return [
            "mtoOperGravadas" => (float) $this->invoice->total_taxable,
            "mtoIGV" => (float) $this->invoice->total_igv,
            "totalImpuestos" => (float) $this->invoice->total_igv,
            "valorVenta" => (float) $this->invoice->total_taxable,
            "subTotal" => (float) ($this->invoice->total_taxable + $this->invoice->total_igv),
            "mtoImpVenta" => (float) $this->invoice->total,
        ];
    }
 
    protected function buildLegends(): array
    {
        $amountInWords = $this->convertAmountToWords(
            (float) $this->invoice->total, 
            $this->invoice->currency === '2' ? 'USD' : 'PEN'
        );
        
        return [
            [
                "code" => "1000",
                "value" => $amountInWords,
            ],
        ];
    }
    
   
    protected function convertAmountToWords(float $amount, string $currency): string
    {
        $integerPart = (int) $amount;
        $decimalPart = (int) round(($amount - $integerPart) * 100);
        
        $currencyName = $currency === 'USD' ? 'DÓLARES' : 'SOLES';
        
        $words = $this->numberToWords($integerPart);
        
        return strtoupper($words) . ' CON ' . str_pad($decimalPart, 2, '0', STR_PAD_LEFT) . '/100 ' . $currencyName;
    }
    
  
    protected function numberToWords(int $number): string
    {
        if ($number == 0) return 'CERO';
        
        $units = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE'];
        $teens = ['DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE'];
        $tens = ['', '', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
        $hundreds = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];
        
        if ($number < 10) {
            return $units[$number];
        } elseif ($number < 20) {
            return $teens[$number - 10];
        } elseif ($number < 100) {
            $ten = intval($number / 10);
            $unit = $number % 10;
            if ($unit == 0) {
                return $tens[$ten];
            } else {
                return $tens[$ten] . ' Y ' . $units[$unit];
            }
        } elseif ($number < 1000) {
            $hundred = intval($number / 100);
            $remainder = $number % 100;
            if ($hundred == 1 && $remainder == 0) {
                return 'CIEN';
            } elseif ($remainder == 0) {
                return $hundreds[$hundred];
            } else {
                return $hundreds[$hundred] . ' ' . $this->numberToWords($remainder);
            }
        } elseif ($number < 1000000) {
            $thousand = intval($number / 1000);
            $remainder = $number % 1000;
            $thousandWords = '';
            
            if ($thousand == 1) {
                $thousandWords = 'MIL';
            } else {
                $thousandWords = $this->numberToWords($thousand) . ' MIL';
            }
            
            if ($remainder == 0) {
                return $thousandWords;
            } else {
                return $thousandWords . ' ' . $this->numberToWords($remainder);
            }
        } elseif ($number < 1000000000) {
            $million = intval($number / 1000000);
            $remainder = $number % 1000000;
            $millionWords = '';
            
            if ($million == 1) {
                $millionWords = 'UN MILLÓN';
            } else {
                $millionWords = $this->numberToWords($million) . ' MILLONES';
            }
            
            if ($remainder == 0) {
                return $millionWords;
            } else {
                return $millionWords . ' ' . $this->numberToWords($remainder);
            }
        }
        
        return 'NÚMERO DEMASIADO GRANDE';
    }
    
   
    abstract public function buildPaymentTerms(): array;
    abstract public function buildSpecificData(): array;
}