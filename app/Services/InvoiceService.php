<?php

namespace App\Services;

use CodersFree\LaravelGreenter\Facades\Greenter;
use App\Models\Invoice;
use App\Services\Invoice\Builders\{
    AbstractInvoiceBuilder,
    NormalInvoiceBuilder,
    CreditInvoiceBuilder, 
    DetractionInvoiceBuilder,
    CreditDetractionInvoiceBuilder
};
use Illuminate\Support\Facades\Log;

class InvoiceService
{
    /**
     * Envía la factura a Nubefact usando la arquitectura de builders extensible
     */
    public function sendToNubefact(Invoice $invoice): array
    {
        $invoice->load('client', 'items');
        
        try {
            // Obtener el builder apropiado según el tipo de factura
            $builder = $this->getInvoiceBuilder($invoice);
            
            // Construir los datos de la factura
            $data = $builder->buildInvoiceData();
            
            // Enviar a Greenter/Nubefact
            $response = Greenter::send('invoice', $data);
            
            return [
                'success' => true,
                'response' => $response,
            ];
            
        } catch (\Exception $e) {
            Log::error('Error enviando factura a Nubefact:', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Obtiene el builder apropiado según las características de la factura
     */
    public function getInvoiceBuilder(Invoice $invoice): AbstractInvoiceBuilder
    {
        $isCredit = !is_null($invoice->due_date);
        $hasDetraction = $invoice->detraction;
        
        // Determinar el tipo de builder según las características de la factura
        if ($isCredit && $hasDetraction) {
            return new CreditDetractionInvoiceBuilder($invoice);
        }
        
        if ($isCredit) {
            return new CreditInvoiceBuilder($invoice);
        }
        
        if ($hasDetraction) {
            return new DetractionInvoiceBuilder($invoice);
        }
        
        return new NormalInvoiceBuilder($invoice);
    }
    
    /**
     * Métodos auxiliares mantenidos para compatibilidad
     * (estos ahora se usan principalmente en AbstractInvoiceBuilder)
     */
    private function convertAmountToWords(float $amount, string $currency): string
    {
        $integerPart = (int) $amount;
        $decimalPart = (int) round(($amount - $integerPart) * 100);
        
        $currencyName = $currency === 'USD' ? 'DÓLARES' : 'SOLES';
        
        $words = $this->numberToWords($integerPart);
        
        return strtoupper($words) . ' CON ' . str_pad($decimalPart, 2, '0', STR_PAD_LEFT) . '/100 ' . $currencyName;
    }

    private function numberToWords(int $number): string
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
}