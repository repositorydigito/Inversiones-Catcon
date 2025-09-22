<?php

namespace App\Services;

use App\Models\Despatch;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class SunatCdrService
{
    /**
     * Procesa el CDR completo desde la respuesta de SUNAT
     */
    public function processCdrFromResponse(array $responseData, Despatch $despatch): array
    {
        if (!isset($responseData['arcCdr']) || $responseData['indCdrGenerado'] !== '1') {
            return [
                'success' => false,
                'error' => 'CDR no disponible en la respuesta'
            ];
        }

        return $this->processCdrBase64($responseData['arcCdr'], $despatch);
    }

    /**
     * Procesa el CDR desde su contenido Base64
     */
    public function processCdrBase64(string $base64Cdr, Despatch $despatch): array
    {
        try {
            Log::info("Procesando CDR para despatch", [
                'despatch_id' => $despatch->id,
                'serie_numero' => $despatch->series . '-' . $despatch->number
            ]);

            // 1. Decodificar y extraer el ZIP
            $extractedData = $this->extractCdrFromBase64($base64Cdr);

            if (!$extractedData['success']) {
                return $extractedData;
            }

            // 2. Encontrar y procesar el XML del CDR
            $xmlFileName = $this->findCdrXmlFile($extractedData['files']);

            if (!$xmlFileName) {
                return [
                    'success' => false,
                    'error' => 'No se encontró archivo XML en el CDR'
                ];
            }

            $xmlContent = $extractedData['files'][$xmlFileName];

            // 3. Extraer información del CDR
            $cdrInfo = $this->extractCdrInformation($xmlContent);

            // 4. Guardar archivos físicamente (opcional)
            $savedFiles = $this->saveCdrFiles($despatch, $base64Cdr, $extractedData['files']);

            // 5. Preparar resultado final
            $result = [
                'success' => true,
                'cdr_info' => $cdrInfo,
                'pdf_url' => $cdrInfo['qr_url'] ?? null,
                'xml_content' => $xmlContent,
                'saved_files' => $savedFiles,
                'analysis' => $this->analyzeCdrNotes($cdrInfo['notes'] ?? [])
            ];

            Log::info("CDR procesado exitosamente", [
                'despatch_id' => $despatch->id,
                'response_code' => $cdrInfo['response_code'] ?? 'unknown',
                'pdf_available' => !empty($result['pdf_url'])
            ]);

            return $result;

        } catch (Exception $e) {
            Log::error("Error procesando CDR", [
                'despatch_id' => $despatch->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Error al procesar CDR: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Extrae el contenido del CDR desde Base64
     */
    protected function extractCdrFromBase64(string $base64Cdr): array
    {
        try {
            // Decodificar Base64
            $zipContent = base64_decode($base64Cdr);
            if ($zipContent === false) {
                return [
                    'success' => false,
                    'error' => 'No se pudo decodificar el Base64 del CDR'
                ];
            }

            // Crear archivo temporal para el ZIP
            $tempDir = storage_path('app/temp/cdr');
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $tempZipFile = $tempDir . '/cdr_' . uniqid() . '.zip';

            if (file_put_contents($tempZipFile, $zipContent) === false) {
                return [
                    'success' => false,
                    'error' => 'No se pudo crear archivo temporal del CDR'
                ];
            }

            // Extraer contenido del ZIP
            $zip = new ZipArchive();
            if ($zip->open($tempZipFile) !== TRUE) {
                unlink($tempZipFile);
                return [
                    'success' => false,
                    'error' => 'No se pudo abrir el archivo ZIP del CDR'
                ];
            }

            $files = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $fileName = $zip->getNameIndex($i);
                $fileContent = $zip->getFromIndex($i);

                if ($fileContent !== false) {
                    $files[$fileName] = $fileContent;
                }
            }

            $zip->close();
            unlink($tempZipFile);

            return [
                'success' => true,
                'files' => $files,
                'zip_size' => strlen($zipContent)
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error extrayendo CDR: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Encuentra el archivo XML del CDR en los archivos extraídos
     */
    protected function findCdrXmlFile(array $files): ?string
    {
        foreach (array_keys($files) as $fileName) {
            // Los CDRs suelen tener el patrón R-RUC-TIPO-SERIE-NUMERO.xml
            if (preg_match('/^R-\d{11}-\d{2}-.+\.xml$/', $fileName)) {
                return $fileName;
            }
        }

        // Fallback: cualquier archivo XML
        foreach (array_keys($files) as $fileName) {
            if (pathinfo($fileName, PATHINFO_EXTENSION) === 'xml') {
                return $fileName;
            }
        }

        return null;
    }

    /**
     * Extrae información relevante del XML del CDR
     */
    protected function extractCdrInformation(string $xmlContent): array
    {
        try {
            $xml = simplexml_load_string($xmlContent);
            if ($xml === false) {
                throw new Exception('No se pudo parsear el XML del CDR');
            }

            // Registrar namespaces
            $xml->registerXPathNamespace('ar', 'urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2');
            $xml->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
            $xml->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

            $info = [
                'success' => true,
                'xml_valid' => true
            ];

            // Información básica
            $info['id'] = $this->getXmlValue($xml, '//cbc:ID');
            $info['issue_date'] = $this->getXmlValue($xml, '//cbc:IssueDate');
            $info['issue_time'] = $this->getXmlValue($xml, '//cbc:IssueTime');
            $info['response_date'] = $this->getXmlValue($xml, '//cbc:ResponseDate');
            $info['response_time'] = $this->getXmlValue($xml, '//cbc:ResponseTime');

            // Información de las partes
            $info['sender_ruc'] = $this->getXmlValue($xml, '//cac:SenderParty/cac:PartyIdentification/cbc:ID');
            $info['receiver_ruc'] = $this->getXmlValue($xml, '//cac:ReceiverParty/cac:PartyIdentification/cbc:ID');

            // Respuesta del documento
            $info['response_code'] = $this->getXmlValue($xml, '//cac:DocumentResponse/cac:Response/cbc:ResponseCode');
            $info['description'] = $this->getXmlValue($xml, '//cac:DocumentResponse/cac:Response/cbc:Description');
            $info['document_id'] = $this->getXmlValue($xml, '//cac:DocumentResponse/cac:DocumentReference/cbc:ID');

            // ¡AQUÍ ESTÁ LA URL DEL QR/PDF!
            $info['qr_url'] = $this->getXmlValue($xml, '//cac:DocumentResponse/cac:DocumentReference/cbc:DocumentDescription');

            // Notas (observaciones/errores)
            $notes = $xml->xpath('//cbc:Note');
            $info['notes'] = [];
            if ($notes) {
                foreach ($notes as $note) {
                    $info['notes'][] = (string) $note;
                }
            }

            return $info;

        } catch (Exception $e) {
            return [
                'success' => false,
                'xml_valid' => false,
                'error' => 'Error procesando XML del CDR: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Obtiene el valor de un elemento XML usando XPath
     */
    protected function getXmlValue($xml, string $xpath): ?string
    {
        $elements = $xml->xpath($xpath);
        return $elements && count($elements) > 0 ? (string) $elements[0] : null;
    }

    /**
     * Guarda los archivos del CDR físicamente
     */
    protected function saveCdrFiles(Despatch $despatch, string $base64Content, array $extractedFiles): array
    {
        try {
            // Crear estructura de directorios por fecha
            $year = $despatch->emission_date->format('Y');
            $month = $despatch->emission_date->format('m');
            $cdrDir = "sunat/{$despatch->company->ruc}/cdr/{$year}/{$month}";

            // Usar disco 'public' para que sea accesible por URL
            \Storage::disk('public')->makeDirectory($cdrDir);

            $savedFiles = [];

            // Guardar ZIP original del CDR
            $zipFileName = "R-{$despatch->company->ruc}-31-{$despatch->series}-{$despatch->number}.zip";
            $zipPath = $cdrDir . '/' . $zipFileName;

            if (\Storage::disk('public')->put($zipPath, base64_decode($base64Content))) {
                $savedFiles['zip'] = [
                    'path' => $zipPath,
                    'url' => \Storage::disk('public')->url($zipPath),
                    'size' => strlen(base64_decode($base64Content))
                ];
            }

            // Guardar XML del CDR extraído
            foreach ($extractedFiles as $fileName => $content) {
                if (pathinfo($fileName, PATHINFO_EXTENSION) === 'xml') {
                    $xmlPath = $cdrDir . '/' . $fileName;
                    if (\Storage::disk('public')->put($xmlPath, $content)) {
                        $savedFiles['xml'] = [
                            'path' => $xmlPath,
                            'url' => \Storage::disk('public')->url($xmlPath),
                            'size' => strlen($content)
                        ];
                    }
                }
            }

            Log::info("Archivos CDR guardados exitosamente", [
                'despatch_id' => $despatch->id,
                'saved_files' => array_keys($savedFiles),
                'cdr_dir' => $cdrDir
            ]);

            return $savedFiles;

        } catch (Exception $e) {
            Log::warning("No se pudieron guardar archivos del CDR", [
                'despatch_id' => $despatch->id,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Analiza las notas del CDR e identifica tipos de errores
     */
    protected function analyzeCdrNotes(array $notes): array
    {
        $analysis = [
            'total_notes' => count($notes),
            'errors' => [],
            'warnings' => [],
            'info' => [],
            'error_codes' => []
        ];

        foreach ($notes as $note) {
            // Extraer código de error si existe
            if (preg_match('/(\d{4})\s*-/', $note, $matches)) {
                $errorCode = $matches[1];
                $analysis['error_codes'][] = $errorCode;

                // Clasificar por tipo de error
                $errorInfo = $this->getErrorInfo($errorCode);

                switch ($errorInfo['severity']) {
                    case 'error':
                        $analysis['errors'][] = [
                            'code' => $errorCode,
                            'message' => $errorInfo['message'],
                            'full_note' => $note
                        ];
                        break;
                    case 'warning':
                        $analysis['warnings'][] = [
                            'code' => $errorCode,
                            'message' => $errorInfo['message'],
                            'full_note' => $note
                        ];
                        break;
                    default:
                        $analysis['info'][] = [
                            'code' => $errorCode,
                            'message' => $errorInfo['message'],
                            'full_note' => $note
                        ];
                }
            }
        }

        $analysis['has_errors'] = count($analysis['errors']) > 0;
        $analysis['has_warnings'] = count($analysis['warnings']) > 0;

        return $analysis;
    }

    /**
     * Obtiene información sobre códigos de error específicos
     */
    protected function getErrorInfo(string $errorCode): array
    {
        $errorMap = [
            '4391' => [
                'severity' => 'error',
                'message' => 'Número de Registro MTC del transportista no existe'
            ],
            '4434' => [
                'severity' => 'warning',
                'message' => 'No corresponde consignar detalle de bienes a transportar'
            ],
            '4399' => [
                'severity' => 'error',
                'message' => 'Falta Constancia de Inscripción Vehicular o Certificado de Habilitación'
            ],
            '4388' => [
                'severity' => 'error',
                'message' => 'Debe consignar el Indicador de pagador de flete'
            ],
            '4398' => [
                'severity' => 'error',
                'message' => 'Número de placa no encontrado en bases de SUNAT'
            ],
            '4412' => [
                'severity' => 'error',
                'message' => 'Número de licencia de conducir no encontrado'
            ]
        ];

        return $errorMap[$errorCode] ?? [
            'severity' => 'info',
            'message' => "Error código {$errorCode}"
        ];
    }

    /**
     * Descarga el PDF directamente desde la URL del QR
     */
    public function downloadPdfFromQr(string $qrUrl): array
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 15,
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ]
            ]);

            $pdfContent = file_get_contents($qrUrl, false, $context);

            if ($pdfContent === false) {
                return [
                    'success' => false,
                    'error' => 'No se pudo descargar el PDF desde la URL del QR'
                ];
            }

            return [
                'success' => true,
                'content' => $pdfContent,
                'size' => strlen($pdfContent),
                'mime_type' => 'application/pdf'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error descargando PDF: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Obtiene un resumen del estado del CDR
     */
    public function getCdrSummary(array $cdrInfo): array
    {
        $responseCode = $cdrInfo['response_code'] ?? '99';

        return [
            'status' => $this->interpretResponseCode($responseCode),
            'is_accepted' => $responseCode === '0',
            'has_pdf' => !empty($cdrInfo['qr_url']),
            'pdf_url' => $cdrInfo['qr_url'] ?? null,
            'document_id' => $cdrInfo['document_id'] ?? null,
            'description' => $cdrInfo['description'] ?? null,
            'issue_datetime' => isset($cdrInfo['issue_date'], $cdrInfo['issue_time'])
                ? $cdrInfo['issue_date'] . ' ' . $cdrInfo['issue_time']
                : null,
            'total_notes' => count($cdrInfo['notes'] ?? [])
        ];
    }

    /**
     * Interpreta el código de respuesta
     */
    protected function interpretResponseCode(string $code): string
    {
        return match ($code) {
            '0' => 'ACEPTADO',
            '2324' => 'OBSERVADO',
            '98' => 'EN PROCESO',
            '99' => 'RECHAZADO',
            default => 'DESCONOCIDO'
        };
    }
}
