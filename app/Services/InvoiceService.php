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

            // Configurar credenciales de NubeFact OSE temporalmente
            $originalUser = config('greenter.company.clave_sol.user');
            $originalPass = config('greenter.company.clave_sol.password');

            config([
                'greenter.company.clave_sol.user' => config('greenter.company.nubefact_ose.user'),
                'greenter.company.clave_sol.password' => config('greenter.company.nubefact_ose.password'),
            ]);

            try {
                // Enviar a Greenter/Nubefact con credenciales correctas
                $result = Greenter::send('invoice', $data);

                Log::info('Respuesta de Greenter recibida:', [
                    'invoice_id' => $invoice->id,
                    'success' => $result->success ?? null,
                    'has_cdr' => isset($result->cdrResponse) && $result->cdrResponse !== null,
                    'result_class' => get_class($result)
                ]);

                // Procesar respuesta y actualizar factura
                $this->processNubefactResponse($invoice, $result);

                return [
                    'success' => $result->success ?? true,
                    'response' => $result,
                ];

            } finally {
                // Restaurar credenciales originales
                config([
                    'greenter.company.clave_sol.user' => $originalUser,
                    'greenter.company.clave_sol.password' => $originalPass,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error enviando factura a Nubefact:', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            // Actualizar factura como rechazada
            $invoice->update([
                'sunat_accepted' => false,
                'sunat_description' => 'Error: ' . $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Procesa la respuesta de Nubefact y actualiza la factura
     */
    protected function processNubefactResponse(Invoice $invoice, $result): void
    {
        $updateData = [];

        // Verificar si la respuesta fue exitosa o no
        $success = $result->success ?? false;

        // Si NO fue exitoso (hay error)
        if (!$success) {
            $updateData['sunat_accepted'] = false;

            // Intentar obtener mensaje de error de diferentes maneras
            if (isset($result->error) && is_object($result->error)) {
                if (method_exists($result->error, 'getMessage')) {
                    $updateData['sunat_description'] = 'Error: ' . $result->error->getMessage();
                } elseif (property_exists($result->error, 'message')) {
                    $updateData['sunat_description'] = 'Error: ' . $result->error->message;
                } else {
                    $updateData['sunat_description'] = 'Error: ' . json_encode($result->error);
                }

                if (method_exists($result->error, 'getCode')) {
                    $updateData['sunat_response_code'] = $result->error->getCode();
                } elseif (property_exists($result->error, 'code')) {
                    $updateData['sunat_response_code'] = $result->error->code;
                }
            } elseif (isset($result->error) && is_string($result->error)) {
                $updateData['sunat_description'] = 'Error: ' . $result->error;
            } else {
                $updateData['sunat_description'] = 'Error desconocido al enviar a SUNAT';
            }

            Log::warning('Factura rechazada o con error:', [
                'invoice_id' => $invoice->id,
                'error_details' => $result->error ?? 'No error details'
            ]);
        }
        // Si fue exitoso y hay respuesta CDR
        elseif (isset($result->cdrResponse) && $result->cdrResponse) {
            $cdr = $result->cdrResponse;
            $updateData['sunat_accepted'] = true;
            $updateData['sunat_response_code'] = $cdr->code ?? null;
            $updateData['sunat_description'] = $cdr->description ?? 'Aceptado por SUNAT';

            // Notas adicionales si existen
            if (property_exists($cdr, 'notes') && is_array($cdr->notes) && count($cdr->notes) > 0) {
                $updateData['sunat_notes'] = implode(' | ', $cdr->notes);
            }

            // CDR en base64 (Constancia de Recepción)
            if (property_exists($result, 'cdrZip') && $result->cdrZip) {
                $updateData['cdr_zip_base64'] = base64_encode($result->cdrZip);
            }

            Log::info('Factura aceptada por SUNAT:', [
                'invoice_id' => $invoice->id,
                'cdr_code' => $cdr->code ?? null,
                'cdr_description' => $cdr->description ?? null
            ]);
        }
        // Exitoso pero sin CDR (estado pendiente o procesando)
        else {
            $updateData['sunat_accepted'] = null;
            $updateData['sunat_description'] = 'Enviado a Nubefact - Esperando respuesta de SUNAT';

            Log::info('Factura enviada, esperando confirmación:', [
                'invoice_id' => $invoice->id,
                'has_cdr' => isset($result->cdrResponse)
            ]);
        }

        // Actualizar la factura
        $invoice->update($updateData);

        Log::info('Factura actualizada con respuesta de Nubefact:', [
            'invoice_id' => $invoice->id,
            'sunat_accepted' => $updateData['sunat_accepted'] ?? null,
            'response_code' => $updateData['sunat_response_code'] ?? 'N/A',
            'description' => $updateData['sunat_description'] ?? 'N/A'
        ]);
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
