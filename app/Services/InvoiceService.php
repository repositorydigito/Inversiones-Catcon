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

                // ============================================
                // CAPTURAR TODA LA INFORMACIÓN DISPONIBLE
                // ============================================

                Log::info('========================================');
                Log::info('RESPUESTA COMPLETA DE GREENTER/NUBEFACT');
                Log::info('========================================');

                // Información básica del objeto
                Log::info('1. INFORMACIÓN DEL OBJETO:', [
                    'invoice_id' => $invoice->id,
                    'result_class' => get_class($result),
                    'result_methods' => get_class_methods($result),
                ]);

                // Intentar obtener el documento
                try {
                    $document = $result->getDocument();
                    Log::info('2. DOCUMENTO:', [
                        'document_class' => get_class($document),
                        'document_name' => method_exists($document, 'getName') ? $document->getName() : 'N/A',
                    ]);
                } catch (\Exception $e) {
                    Log::warning('No se pudo obtener documento:', ['error' => $e->getMessage()]);
                }

                // Intentar obtener el CDR Response
                try {
                    $cdrResponse = $result->getCdrResponse();
                    Log::info('3. CDR RESPONSE:', [
                        'cdr_exists' => $cdrResponse !== null,
                        'cdr_class' => $cdrResponse ? get_class($cdrResponse) : null,
                        'cdr_methods' => $cdrResponse ? get_class_methods($cdrResponse) : null,
                    ]);

                    if ($cdrResponse) {
                        Log::info('4. DATOS DEL CDR RESPONSE:', [
                            'id' => method_exists($cdrResponse, 'getId') ? $cdrResponse->getId() : 'N/A',
                            'code' => method_exists($cdrResponse, 'getCode') ? $cdrResponse->getCode() : 'N/A',
                            'description' => method_exists($cdrResponse, 'getDescription') ? $cdrResponse->getDescription() : 'N/A',
                            'notes' => method_exists($cdrResponse, 'getNotes') ? $cdrResponse->getNotes() : [],
                            'reference' => method_exists($cdrResponse, 'getReference') ? $cdrResponse->getReference() : 'N/A',
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('Error al obtener CDR Response:', ['error' => $e->getMessage()]);
                }

                // Intentar usar readCdr()
                try {
                    $cdrData = $result->readCdr();
                    Log::info('5. READ CDR (MÉTODO):', [
                        'cdr_data' => $cdrData,
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Error al ejecutar readCdr():', ['error' => $e->getMessage()]);
                }

                // Intentar obtener el CDR Zip
                try {
                    $cdrZip = $result->getCdrZip();
                    Log::info('6. CDR ZIP:', [
                        'has_cdr_zip' => $cdrZip !== null,
                        'cdr_zip_length' => $cdrZip ? strlen($cdrZip) : 0,
                        'cdr_zip_preview' => $cdrZip ? substr(base64_encode($cdrZip), 0, 50) . '...' : null,
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Error al obtener CDR Zip:', ['error' => $e->getMessage()]);
                }

                // Intentar obtener el XML
                try {
                    $xml = $result->getXml();
                    Log::info('7. XML:', [
                        'has_xml' => $xml !== null,
                        'xml_length' => $xml ? strlen($xml) : 0,
                        'xml_preview' => $xml ? substr($xml, 0, 100) . '...' : null,
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Error al obtener XML:', ['error' => $e->getMessage()]);
                }

                // Intentar obtener el Hash
                try {
                    $hash = $result->getHash();
                    Log::info('8. HASH:', [
                        'hash' => $hash,
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Error al obtener Hash:', ['error' => $e->getMessage()]);
                }

                // Verificar si hay propiedades públicas accesibles
                try {
                    $publicProperties = get_object_vars($result);
                    Log::info('9. PROPIEDADES PÚBLICAS:', [
                        'properties' => array_keys($publicProperties),
                        'values' => $publicProperties,
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Error al obtener propiedades públicas:', ['error' => $e->getMessage()]);
                }

                // Intentar serializar todo el objeto
                try {
                    Log::info('10. OBJETO COMPLETO (JSON):', [
                        'result_json' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Error al serializar objeto:', ['error' => $e->getMessage()]);
                }

                Log::info('========================================');
                Log::info('FIN DE CAPTURA DE INFORMACIÓN');
                Log::info('========================================');

                // Procesar respuesta y actualizar factura
                $this->processNubefactResponse($invoice, $result);

                // Determinar éxito basado en la presencia de CDR o ausencia de error
                $hasCdr = false;
                try {
                    $hasCdr = $result->getCdrResponse() !== null;
                } catch (\Exception $e) {
                    // Ignorar
                }

                return [
                    'success' => $hasCdr,
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
     * Consulta el estado actual de una factura en Nubefact/SUNAT usando la API REST
     * Este método se usa cuando la factura ya fue enviada y queremos obtener el CDR
     */
    public function consultarEstado(Invoice $invoice): array
    {
        try {
            Log::info('Consultando estado de factura en Nubefact via API REST:', [
                'invoice_id' => $invoice->id,
                'series' => $invoice->series,
                'number' => $invoice->number
            ]);

            // Construir los parámetros para la consulta
            $ruc = config('greenter.company.ruc');
            $tipoDoc = $invoice->invoice_type ?? '01'; // 01 = Factura
            $serie = $invoice->series;
            $numero = $invoice->number;

            // URL de la API de Nubefact para consultar estado
            $url = "https://api.nubefact.com/v1/api/consultarestado";

            // Credenciales de Nubefact
            $token = config('greenter.company.nubefact_ose.token'); // Si tienes token guardado
            // Si no tienes token, usaremos user y password
            $user = config('greenter.company.nubefact_ose.user');
            $password = config('greenter.company.nubefact_ose.password');

            // Preparar datos de la consulta
            $requestData = [
                'operacion' => 'consultar_comprobante',
                'tipo_de_comprobante' => $tipoDoc,
                'serie' => $serie,
                'numero' => $numero
            ];

            Log::info('Enviando consulta a Nubefact API:', [
                'url' => $url,
                'data' => $requestData
            ]);

            // Realizar la petición HTTP
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . ($token ?? base64_encode($user . ':' . $password))
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new \Exception("Error de conexión: " . $curlError);
            }

            $responseData = json_decode($response, true);

            Log::info('Respuesta de Nubefact API:', [
                'invoice_id' => $invoice->id,
                'http_code' => $httpCode,
                'response' => $responseData
            ]);

            // Procesar la respuesta
            if (isset($responseData['aceptada_por_sunat'])) {
                $updateData = [];

                if ($responseData['aceptada_por_sunat'] === true || $responseData['aceptada_por_sunat'] === 1) {
                    $updateData['sunat_accepted'] = true;
                    $updateData['sunat_response_code'] = $responseData['codigo_sunat'] ?? '0';
                    $updateData['sunat_description'] = $responseData['descripcion_sunat'] ?? 'Aceptado por SUNAT';

                    // Si viene el CDR en base64
                    if (isset($responseData['cdr'])) {
                        $updateData['cdr_zip_base64'] = $responseData['cdr'];
                    }

                    $invoice->update($updateData);

                    Log::info('Factura actualizada como ACEPTADA:', [
                        'invoice_id' => $invoice->id,
                        'code' => $updateData['sunat_response_code']
                    ]);

                    return [
                        'success' => true,
                        'message' => 'Factura aceptada por SUNAT',
                        'sunat_accepted' => true,
                        'cdr_code' => $updateData['sunat_response_code']
                    ];

                } elseif ($responseData['aceptada_por_sunat'] === false || $responseData['aceptada_por_sunat'] === 0) {
                    $updateData['sunat_accepted'] = false;
                    $updateData['sunat_response_code'] = $responseData['codigo_sunat'] ?? null;
                    $updateData['sunat_description'] = $responseData['descripcion_sunat'] ?? 'Rechazado por SUNAT';

                    $invoice->update($updateData);

                    Log::warning('Factura actualizada como RECHAZADA:', [
                        'invoice_id' => $invoice->id,
                        'code' => $updateData['sunat_response_code']
                    ]);

                    return [
                        'success' => true,
                        'message' => 'Factura rechazada por SUNAT',
                        'sunat_accepted' => false,
                        'description' => $updateData['sunat_description']
                    ];
                }
            }

            // Si no hay información clara, mantener como pendiente
            return [
                'success' => true,
                'message' => 'La factura aún está en proceso o no se encontró información. Verifique en el portal de Nubefact.',
                'sunat_accepted' => null,
                'requires_manual_check' => true
            ];

        } catch (\Exception $e) {
            Log::error('Error consultando estado de factura via API:', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return [
                'success' => false,
                'message' => 'Error al consultar: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Procesa la respuesta de Nubefact y actualiza la factura
     *
     * Estados posibles:
     * - ERROR: Cuando no se puede obtener el CDR Response
     * - ACEPTADO/RECHAZADO: Cuando hay CDR con respuesta de SUNAT
     * - PENDIENTE: Cuando no hay CDR aún (respuesta asíncrona)
     */
    protected function processNubefactResponse(Invoice $invoice, $result): void
    {
        $updateData = [];

        try {
            // Intentar obtener el CDR Response
            $cdrResponse = $result->getCdrResponse();

            if ($cdrResponse) {
                Log::info('Procesando CDR Response existente:', [
                    'invoice_id' => $invoice->id,
                    'cdr_class' => get_class($cdrResponse)
                ]);

                // Obtener datos del CDR usando los métodos del objeto
                $cdrCode = method_exists($cdrResponse, 'getCode') ? $cdrResponse->getCode() : null;
                $cdrDescription = method_exists($cdrResponse, 'getDescription') ? $cdrResponse->getDescription() : null;
                $cdrId = method_exists($cdrResponse, 'getId') ? $cdrResponse->getId() : null;
                $cdrNotes = method_exists($cdrResponse, 'getNotes') ? $cdrResponse->getNotes() : [];
                $cdrReference = method_exists($cdrResponse, 'getReference') ? $cdrResponse->getReference() : null;

                // También intentar usar readCdr()
                try {
                    $cdrData = $result->readCdr();
                    Log::info('Datos del CDR via readCdr():', $cdrData);

                    // Usar los datos de readCdr si están disponibles
                    $cdrCode = $cdrCode ?? ($cdrData['code'] ?? null);
                    $cdrDescription = $cdrDescription ?? ($cdrData['description'] ?? null);
                    $cdrId = $cdrId ?? ($cdrData['id'] ?? null);
                    $cdrNotes = $cdrNotes ?: ($cdrData['notes'] ?? []);
                } catch (\Exception $e) {
                    Log::warning('No se pudo usar readCdr():', ['error' => $e->getMessage()]);
                }

                // Verificar el código de respuesta de SUNAT
                // Códigos que empiezan con 0 = aceptado
                // Códigos que empiezan con 2 = aceptado con observaciones
                // Códigos que empiezan con 4 = rechazado
                $cdrCodeStr = (string)$cdrCode;
                $isAccepted = in_array(substr($cdrCodeStr, 0, 1), ['0', '2']);

                $updateData['sunat_accepted'] = $isAccepted;
                $updateData['sunat_response_code'] = $cdrCode;
                $updateData['sunat_description'] = $cdrDescription ?? ($isAccepted ? 'Aceptado por SUNAT' : 'Rechazado por SUNAT');

                // Notas adicionales si existen
                if (is_array($cdrNotes) && count($cdrNotes) > 0) {
                    $updateData['sunat_notes'] = implode(' | ', $cdrNotes);
                }

                // ============================================
                // GUARDAR CDR ZIP en base64
                // ============================================
                try {
                    $cdrZip = $result->getCdrZip();
                    if ($cdrZip) {
                        $updateData['cdr_zip_base64'] = base64_encode($cdrZip);
                        Log::info('CDR Zip guardado:', [
                            'invoice_id' => $invoice->id,
                            'size' => strlen($cdrZip)
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('No se pudo obtener CDR Zip:', ['error' => $e->getMessage()]);
                }

                // ============================================
                // GUARDAR XML COMPRIMIDO en base64
                // ============================================
                try {
                    $xml = $result->getXml();
                    if ($xml) {
                        // Comprimir el XML con gzip y convertir a base64
                        $xmlCompressed = gzencode($xml, 9); // Nivel 9 = máxima compresión
                        $updateData['xml_zip_base64'] = base64_encode($xmlCompressed);

                        Log::info('XML comprimido y guardado:', [
                            'invoice_id' => $invoice->id,
                            'original_size' => strlen($xml),
                            'compressed_size' => strlen($xmlCompressed),
                            'compression_ratio' => round((1 - strlen($xmlCompressed) / strlen($xml)) * 100, 2) . '%'
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('No se pudo obtener/comprimir XML:', ['error' => $e->getMessage()]);
                }

                // ============================================
                // GUARDAR HASH del XML
                // ============================================
                try {
                    $hash = $result->getHash();
                    if ($hash) {
                        $updateData['xml_hash'] = $hash;
                        Log::info('Hash XML guardado:', [
                            'invoice_id' => $invoice->id,
                            'hash' => $hash
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('No se pudo obtener Hash:', ['error' => $e->getMessage()]);
                }

                Log::info($isAccepted ? 'Factura aceptada por SUNAT:' : 'Factura rechazada por SUNAT:', [
                    'invoice_id' => $invoice->id,
                    'cdr_code' => $cdrCode,
                    'cdr_description' => $cdrDescription,
                    'cdr_id' => $cdrId,
                    'files_saved' => [
                        'cdr' => isset($updateData['cdr_zip_base64']),
                        'xml' => isset($updateData['xml_zip_base64']),
                        'hash' => isset($updateData['xml_hash'])
                    ]
                ]);
            } else {
                // No hay CDR Response = pendiente
                $updateData['sunat_accepted'] = null;
                $updateData['sunat_description'] = 'Enviado a Nubefact OSE - Procesando en SUNAT';

                Log::info('Factura enviada correctamente - Esperando confirmación de SUNAT:', [
                    'invoice_id' => $invoice->id,
                    'note' => 'Esta es una respuesta asíncrona normal de Nubefact OSE'
                ]);
            }

        } catch (\Exception $e) {
            // Error al obtener el CDR Response
            Log::error('Error al procesar respuesta de Nubefact:', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Verificar si es un error conocido
            $errorMessage = $e->getMessage();
            $errorCode = $e->getCode();

            // ERROR 1033: Comprobante ya registrado previamente
            if ($errorCode == 1033 || $errorCode == '1033' ||
                strpos($errorMessage, '1033') !== false ||
                strpos($errorMessage, 'registrado previamente') !== false) {

                $updateData['sunat_accepted'] = null;
                $updateData['sunat_description'] = 'Comprobante ya registrado - Verificar en portal Nubefact';

                Log::info('Comprobante ya registrado previamente (Error 1033):', [
                    'invoice_id' => $invoice->id,
                    'series' => $invoice->series,
                    'number' => $invoice->number
                ]);
            }
            // Otros errores
            else {
                $updateData['sunat_accepted'] = false;
                $updateData['sunat_response_code'] = $errorCode;
                $updateData['sunat_description'] = 'Error: ' . $errorMessage;

                Log::warning('Error al procesar factura:', [
                    'invoice_id' => $invoice->id,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage
                ]);
            }
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
