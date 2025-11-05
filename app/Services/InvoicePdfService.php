<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class InvoicePdfService
{

    public function generateInvoicePdf(Invoice $invoice)
    {
        // Cargar relaciones necesarias
        $invoice->load(['client', 'items.unitOfMeasure', 'installments', 'despatches']);

        // Datos para la vista
        $data = $this->prepareInvoiceData($invoice);

        // Configurar el PDF
        $pdf = PDF::loadView('pdfs.invoice', $data);
        $pdf->setPaper('A4', 'portrait');
        $pdf->setOptions([
            'dpi' => 150,
            'defaultFont' => 'Arial',
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
        ]);

        // Nombre del archivo
        $filename = $this->generateFileName($invoice);

        // Retornar el PDF para descarga
        return $pdf->download($filename);
    }


    public function generateAndStorePdf(Invoice $invoice): string
    {
        $invoice->load(['client', 'items.unitOfMeasure', 'installments', 'despatches']);
        $data = $this->prepareInvoiceData($invoice);

        $pdf = PDF::loadView('pdfs.invoice', $data);
        $pdf->setPaper('A4', 'portrait');

        $filename = $this->generateFileName($invoice);
        $path = 'invoices/pdfs/' . $filename;

        // Guardar en storage
        Storage::disk('public')->put($path, $pdf->output());

        return Storage::disk('public')->url($path);
    }


    private function prepareInvoiceData(Invoice $invoice): array
    {
        // Información de la empresa (debería venir de configuración o base de datos)
        $company = [
            'name' => 'INVERSIONES CATCON S.A.C.',
            'ruc' => '20601921023',
            'address' => 'CAL. GERMAN SCHEREIBER 276 URB. SANTA ANA ENTRE LA CUADRA 1 Y 2 CANAVAL Y MOREYRA.',
            'ubigeo' => 'SAN ISIDRO - LIMA - LIMA',
            'phone' => '(01) 987-654-321',
            'email' => 'facturacion@inversionescatcon.com',
            'website' => 'www.inversionescatcon.com',
            'description' => 'Empresa líder en servicios de transporte y logística',
        ];

        // Convertir monto a palabras
        $amountInWords = $this->convertAmountToWords($invoice->total, $invoice->currency ?? 'PEN');

        // Información de detracción
        $detraction = null;
        if ($invoice->detraction) {
            $detraction = [
                'percentage' => $invoice->detraction_percentage,
                'amount' => $invoice->total_detraction,
                'net_payable' => $invoice->net_payable_amount,
                'account' => $invoice->detraction_bank_account ?? 'Cuenta BN: 00-000-000000'
            ];
        }

        // Tipo de moneda
        $currencySymbol = $invoice->currency === 'USD' ? 'US$' : 'S/';

        return [
            'invoice' => $invoice,
            'company' => $company,
            'amount_in_words' => $amountInWords,
            'detraction' => $detraction,
            'currency_symbol' => $currencySymbol,
            'generated_at' => Carbon::now(),
            'qr_code' => $this->generateQRCode($invoice),
        ];
    }


    private function generateFileName(Invoice $invoice): string
    {
        $clientName = $this->sanitizeFileName($invoice->client->name ?? 'Cliente');
        $date = $invoice->emission_date->format('Y-m-d');

        return "Factura-{$invoice->series}-{$invoice->number}-{$clientName}-{$date}.pdf";
    }


    private function sanitizeFileName(string $filename): string
    {
        // Remover caracteres especiales y espacios
        $filename = preg_replace('/[^A-Za-z0-9\-_]/', '_', $filename);
        $filename = preg_replace('/_+/', '_', $filename);
        return trim($filename, '_');
    }


    private function convertAmountToWords(float $amount, string $currency): string
    {
        $integerPart = (int) $amount;
        $decimalPart = (int) round(($amount - $integerPart) * 100);

        $currencyName = $currency === 'USD' ? 'DÓLARES AMERICANOS' : 'SOLES';

        $words = $this->numberToWords($integerPart);

        return strtoupper($words) . ' CON ' . str_pad($decimalPart, 2, '0', STR_PAD_LEFT) . '/100 ' . $currencyName;
    }


    private function numberToWords(int $number): string
    {
        if ($number === 0) return 'CERO';

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
            return $tens[$ten] . ($unit > 0 ? ' Y ' . $units[$unit] : '');
        } elseif ($number < 1000) {
            $hundred = intval($number / 100);
            $remainder = $number % 100;
            $result = $hundred === 1 && $remainder === 0 ? 'CIEN' : $hundreds[$hundred];
            return $result . ($remainder > 0 ? ' ' . $this->numberToWords($remainder) : '');
        } elseif ($number < 1000000) {
            $thousand = intval($number / 1000);
            $remainder = $number % 1000;
            $thousandText = $thousand === 1 ? 'MIL' : $this->numberToWords($thousand) . ' MIL';
            return $thousandText . ($remainder > 0 ? ' ' . $this->numberToWords($remainder) : '');
        }

        return 'NÚMERO DEMASIADO GRANDE';
    }


    private function generateQRCode(Invoice $invoice): ?string
    {
        // Si ya tiene QR code almacenado, lo usa
        if ($invoice->qr_code_string) {
            return $invoice->qr_code_string;
        }

        // Genera QR básico con datos de la factura
        $qrData = "{$invoice->series}|{$invoice->number}|{$invoice->emission_date->format('Y-m-d')}|{$invoice->total}";

        return base64_encode($qrData);
    }
}
