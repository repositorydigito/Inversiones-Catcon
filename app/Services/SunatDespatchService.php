<?php

namespace App\Services;

use App\Models\Despatch;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SunatDespatchService
{
    protected $xmlGenerator;
    protected $zipService;
    protected $httpClient;

    public function __construct(
        SunatXmlGenerator $xmlGenerator,
        SunatZipService $zipService,
        SunatHttpClient $httpClient
    ) {
        $this->xmlGenerator = $xmlGenerator;
        $this->zipService = $zipService;
        $this->httpClient = $httpClient;
    }

    /**
     * Envía una GRE Transportista a SUNAT
     */
    public function sendDespatch(Despatch $despatch): array
    {
        DB::beginTransaction();

        try {
            Log::info("Iniciando envío de GRE a SUNAT", [
                'despatch_id' => $despatch->id,
                'serie' => $despatch->series,
                'numero' => $despatch->number
            ]);

            // 1. Generar XML
            $xmlContent = $this->xmlGenerator->generateXml($despatch);
            $xmlFileName = $this->xmlGenerator->generateXmlFileName($despatch);

            Log::info("XML generado exitosamente", [
                'xml_file_name' => $xmlFileName,
                'xml_size' => strlen($xmlContent)
            ]);

            // 2. Validar XML
            $this->xmlGenerator->validateXml($xmlContent);

            // 3. Comprimir a ZIP y codificar en Base64
            $zipData = $this->zipService->compressAndEncode($xmlContent, $xmlFileName);

            Log::info("ZIP creado y codificado exitosamente", [
                'zip_size' => $zipData['zip_size'],
                'base64_size' => $zipData['base64_size']
            ]);

            // 4. Preparar payload para SUNAT
            $endpoint = $this->httpClient->buildGreTransportEndpoint($xmlContent);
            $payload = $this->zipService->generateSunatPayload($zipData['base64_content'], $xmlFileName);

            Log::info("Enviando a SUNAT", [
                'endpoint' => $endpoint,
                'payload_size' => strlen(json_encode($payload))
            ]);

            // 5. Enviar a SUNAT
            $response = $this->httpClient->post($endpoint, $payload);

            // 6. Manejar respuesta
            $this->httpClient->handleErrorResponse($response);
            $responseData = $response->json();

            Log::info("Respuesta de SUNAT recibida", [
                'status_code' => $response->status(),
                'response_data' => $responseData
            ]);

            // 7. Actualizar registro en base de datos
            $this->updateDespatchWithResponse($despatch, $responseData, $zipData);

            DB::commit();

            return [
                'success' => true,
                'ticket' => $responseData['numTicket'] ?? null, // Usar numTicket según documentación
                'message' => 'GRE enviada exitosamente a SUNAT',
                'sunat_response' => $responseData,
                'xml_data' => [
                    'file_name' => $xmlFileName,
                    'size' => strlen($xmlContent)
                ],
                'zip_data' => [
                    'size' => $zipData['zip_size'],
                    'hash' => $zipData['hash_zip']
                ]
            ];

        } catch (Exception $e) {
            DB::rollBack();

            Log::error("Error al enviar GRE a SUNAT", [
                'despatch_id' => $despatch->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Actualizar registro con error
            $this->updateDespatchWithError($despatch, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Consulta el estado de una GRE en SUNAT usando el ticket
     */
    public function consultDespatchStatus(Despatch $despatch): array
    {
        try {
            Log::info("Consultando estado de GRE en SUNAT", [
                'despatch_id' => $despatch->id,
                'ticket' => $despatch->sunat_ticket
            ]);

            if (empty($despatch->sunat_ticket)) {
                throw new Exception('La GRE no tiene ticket de SUNAT para consultar');
            }

            // Construir endpoint con el ticket
            $endpoint = $this->httpClient->buildGreStatusEndpoint($despatch->sunat_ticket);

            // Realizar consulta
            $response = $this->httpClient->get($endpoint);
            $this->httpClient->handleErrorResponse($response);
            
            $responseData = $response->json();

            Log::info("Estado consultado exitosamente", [
                'despatch_id' => $despatch->id,
                'status_data' => $responseData
            ]);

            // Actualizar registro con nueva información
            $this->updateDespatchWithStatusResponse($despatch, $responseData);

            // CORREGIDO: Determinar éxito basado en codRespuesta
            $codRespuesta = $responseData['codRespuesta'] ?? '99';
            $isSuccess = $codRespuesta === '0'; // Solo '0' es éxito

            return [
                'success' => $isSuccess,
                'status' => $this->interpretResponseCode($codRespuesta),
                'sunat_response' => $responseData,
                'message' => $isSuccess 
                    ? 'Guía aceptada por SUNAT' 
                    : 'Guía no aceptada por SUNAT',
                'cod_respuesta' => $codRespuesta,
            ];

        } catch (Exception $e) {
            Log::error("Error al consultar estado de GRE", [
                'despatch_id' => $despatch->id,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Actualiza el registro Despatch con la respuesta de envío de SUNAT
     */
    protected function updateDespatchWithResponse(Despatch $despatch, array $responseData, array $zipData): void
    {
        $updateData = [
            'sunat_ticket' => $responseData['numTicket'] ?? null, // Usar numTicket según la documentación
            'sunat_response_code' => null, // Se llenará cuando consultemos el estado
            'sunat_description' => 'Enviado a SUNAT, esperando procesamiento',
            'sunat_note' => isset($responseData['fecRecepcion']) ? 'Recibido: ' . $responseData['fecRecepcion'] : null,
            'xml_file_name' => $zipData['xml_file_name'],
            'zip_hash' => $zipData['hash_zip'],
            'accepted_by_sunat' => false, // Inicialmente false hasta confirmar con consulta
        ];

        $despatch->update($updateData);
    }

    /**
     * Actualiza el registro Despatch con respuesta de consulta de estado
     */
    protected function updateDespatchWithStatusResponse(Despatch $despatch, array $responseData): void
    {
        $codRespuesta = $responseData['codRespuesta'] ?? '99';
        
        $updateData = [
            'sunat_response_code' => $codRespuesta,
            'accepted_by_sunat' => $codRespuesta === '0', // 0 = éxito
        ];

        // Interpretar códigos de respuesta según documentación
        switch ($codRespuesta) {
            case '0':
                $updateData['sunat_description'] = 'Aceptado por SUNAT';
                $updateData['accepted_by_sunat'] = true;
                break;
            case '98':
                $updateData['sunat_description'] = 'En proceso de validación';
                $updateData['accepted_by_sunat'] = false;
                break;
            case '99':
                $updateData['sunat_description'] = 'Rechazado por SUNAT';
                $updateData['accepted_by_sunat'] = false;
                // Manejar errores
                if (isset($responseData['error'])) {
                    $error = $responseData['error'];
                    $updateData['sunat_soap_error'] = "Error {$error['numError']}: {$error['desError']}";
                }
                break;
            default:
                $updateData['sunat_description'] = "Estado desconocido: {$codRespuesta}";
                $updateData['accepted_by_sunat'] = false;
        }

        // Manejar CDR si está disponible
        if (isset($responseData['arcCdr']) && $responseData['indCdrGenerado'] === '1') {
            // El CDR viene en Base64, podrías guardarlo o generar URL de descarga
            $updateData['enlace_del_cdr'] = $this->processCdrContent($responseData['arcCdr'], $despatch);
        }

        // Actualizar notas con información adicional
        if (isset($responseData['indCdrGenerado'])) {
            $cdrStatus = $responseData['indCdrGenerado'] === '1' ? 'CDR generado' : 'CDR no generado';
            $updateData['sunat_note'] = ($despatch->sunat_note ?? '') . " | {$cdrStatus}";
        }

        $despatch->update($updateData);
    }

    /**
     * Interpreta el código de respuesta SUNAT
     */
    protected function interpretResponseCode(string $code): string
    {
        return match ($code) {
            '0' => 'ACEPTADO',
            '98' => 'EN_PROCESO',
            '99' => 'RECHAZADO',
            default => 'DESCONOCIDO'
        };
    }

    /**
     * Procesa el contenido CDR y retorna URL o path
     */
    protected function processCdrContent(string $base64Cdr, Despatch $despatch): ?string
    {
        try {
            // Decodificar CDR
            $cdrContent = base64_decode($base64Cdr);
            
            // Crear directorio para CDRs si no existe
            $cdrDir = storage_path('app/sunat/cdr');
            if (!is_dir($cdrDir)) {
                mkdir($cdrDir, 0755, true);
            }
            
            // Generar nombre de archivo CDR
            $cdrFileName = "R-{$despatch->company->ruc}-31-{$despatch->series}-{$despatch->number}.zip";
            $cdrPath = $cdrDir . '/' . $cdrFileName;
            
            // Guardar CDR
            if (file_put_contents($cdrPath, $cdrContent) !== false) {
                // Retornar URL relativa para descarga
                return '/storage/sunat/cdr/' . $cdrFileName;
            }
            
        } catch (Exception $e) {
            Log::error("Error procesando CDR", [
                'despatch_id' => $despatch->id,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }

    /**
     * Actualiza el registro Despatch con información de error
     */
    protected function updateDespatchWithError(Despatch $despatch, string $errorMessage): void
    {
        $despatch->update([
            'accepted_by_sunat' => false,
            'sunat_soap_error' => $errorMessage,
            'sunat_description' => 'Error al procesar en SUNAT',
        ]);
    }

    /**
     * Verifica si SUNAT está disponible
     */
    public function isSunatAvailable(): bool
    {
        try {
            return $this->httpClient->isServiceAvailable();
        } catch (Exception $e) {
            Log::warning("SUNAT no disponible", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Valida que la GRE esté lista para enviar
     */
    public function validateDespatchForSending(Despatch $despatch): array
    {
        $errors = [];

        // Validaciones básicas
        if (!$despatch->company_id) {
            $errors[] = 'La GRE debe tener una empresa asociada';
        }

        if (!$despatch->client_id) {
            $errors[] = 'La GRE debe tener un cliente asociado';
        }

        if (!$despatch->vehicle_id) {
            $errors[] = 'La GRE debe tener un vehículo principal';
        }

        if (!$despatch->driver_id) {
            $errors[] = 'La GRE debe tener un conductor principal';
        }

        if ($despatch->items()->count() === 0) {
            $errors[] = 'La GRE debe tener al menos un ítem';
        }

        // Validar ubicaciones
        if (empty($despatch->departure_ubigeo)) {
            $errors[] = 'Debe especificar el ubigeo de partida';
        }

        if (empty($despatch->arrival_ubigeo)) {
            $errors[] = 'Debe especificar el ubigeo de llegada';
        }

        // Validar peso
        if ($despatch->total_gross_weight <= 0) {
            $errors[] = 'El peso bruto total debe ser mayor a 0';
        }

        // Validar fechas
        if ($despatch->emission_date->isFuture()) {
            $errors[] = 'La fecha de emisión no puede ser futura';
        }

        if ($despatch->transfer_start_date->isBefore($despatch->emission_date)) {
            $errors[] = 'La fecha de inicio de traslado no puede ser anterior a la emisión';
        }

        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $this->getValidationWarnings($despatch)
        ];
    }

    /**
     * Obtiene advertencias (no bloquean el envío pero son recomendaciones)
     */
    protected function getValidationWarnings(Despatch $despatch): array
    {
        $warnings = [];

        if (empty($despatch->observations)) {
            $warnings[] = 'Se recomienda agregar observaciones a la GRE';
        }

        if ($despatch->secondaryVehicles()->count() === 0) {
            $warnings[] = 'No se han agregado vehículos secundarios';
        }

        return $warnings;
    }

    /**
     * Obtiene estadísticas de envío para dashboard
     */
    public function getStats(): array
    {
        return [
            'total_sent' => Despatch::whereNotNull('sunat_ticket')->count(),
            'accepted' => Despatch::where('accepted_by_sunat', true)->count(),
            'pending' => Despatch::whereNotNull('sunat_ticket')
                                ->where('accepted_by_sunat', false)
                                ->orWhereNull('accepted_by_sunat')
                                ->count(),
            'errors' => Despatch::whereNotNull('sunat_soap_error')->count(),
            'today_sent' => Despatch::whereNotNull('sunat_ticket')
                                  ->whereDate('created_at', today())
                                  ->count(),
        ];
    }
}