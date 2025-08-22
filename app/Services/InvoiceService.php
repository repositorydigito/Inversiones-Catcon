<?php

namespace App\Services;

use CodersFree\LaravelGreenter\Facades\Greenter;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

class InvoiceService
{
    public function sendToNubefact(Invoice $invoice): array
    {
        $invoice->load('client', 'items');
        
        $currencyMap = [
            '1' => 'PEN',
            '2' => 'USD'
        ];
        
        $paymentType = is_null($invoice->due_date) ? 'Contado' : 'Crédito';
        
        $details = [];
        foreach ($invoice->items as $item) {
            $details[] = [
                "codProducto" => $item->code ?? '',
                "unidad" => "NIU",
                "cantidad" => (float) $item->quantity,
                "mtoValorUnitario" => (float) $item->unit_value,
                "descripcion" => $item->description,
                "mtoBaseIgv" => (float) ($item->quantity * $item->unit_value),
                "porcentajeIgv" => (float) $invoice->igv_percentage,
                "igv" => (float) $item->igv,
                "tipAfeIgv" => "10",
                "totalImpuestos" => (float) $item->igv,
                "mtoValorVenta" => (float) $item->subtotal,
                "mtoPrecioUnitario" => (float) $item->unit_price,
            ];
        }
        
        $amountInWords = $this->convertAmountToWords((float) $invoice->total, $currencyMap[$invoice->currency] ?? 'PEN');

        $data = [
            "ublVersion" => "2.1",
            "tipoOperacion" => $invoice->transaction_type,
            "tipoDoc" => $invoice->invoice_type,
            "serie" => $invoice->series,
            "correlativo" => (string) $invoice->number,
            "fechaEmision" => $invoice->emission_date->format('Y-m-d'),
            "formaPago" => [
                'tipo' => $paymentType,
            ],
            "tipoMoneda" => $currencyMap[$invoice->currency] ?? 'PEN',
            "client" => [
                "tipoDoc" => ($invoice->client->document_type === 'DNI') ? '1' : '6',
                "numDoc" => $invoice->client->document_number,
                "rznSocial" => $invoice->client->name,
            ],
            "mtoOperGravadas" => (float) $invoice->total_taxable,
            "mtoIGV" => (float) $invoice->total_igv,
            "totalImpuestos" => (float) $invoice->total_igv,
            "valorVenta" => (float) $invoice->total_taxable,
            "subTotal" => (float) ($invoice->total_taxable + $invoice->total_igv),
            "mtoImpVenta" => (float) $invoice->total,
            "details" => $details,
            "legends" => [
                [
                    "code" => "1000",
                    "value" => $amountInWords,
                ],
            ],
        ];

        try {
            $response = Greenter::send('invoice', $data);
        } catch (\Exception $e) {
            throw $e;
        }

        return [
            'success' => true,
            'response' => $response,
        ];
    }

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