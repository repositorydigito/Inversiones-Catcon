<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use Exception;

class SunatHttpClient
{
    protected $authService;
    protected $timeout;
    protected $retryAttempts;
    protected $ruc;

    public function __construct(SunatAuthService $authService)
    {
        $this->authService = $authService;
        $this->timeout = env('SUNAT_TIMEOUT', 30);
        $this->retryAttempts = env('SUNAT_RETRY_ATTEMPTS', 3);
        $this->ruc = env('GREENTER_COMPANY_RUC', '20601921023');
    }

    /**
     * Realiza una petición POST a SUNAT con autenticación automática
     * Para GRE solo usa Authorization header
     */
    public function post(string $endpoint, array $data, int $attempt = 1): Response
    {
        try {
            $headers = $this->authService->getAuthHeaders(); // Solo Authorization: Bearer
            
            $response = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->post($this->buildUrl($endpoint), $data);

            // Si hay error de autenticación, invalidar caché y reintentar
            if (in_array($response->status(), [401, 403]) && $attempt === 1) {
                $this->authService->invalidateToken();
                return $this->post($endpoint, $data, $attempt + 1);
            }

            return $response;

        } catch (Exception $e) {
            // Reintentar en caso de errores de conexión
            if ($attempt < $this->retryAttempts) {
                sleep(2); // Esperar 2 segundos antes de reintentar
                return $this->post($endpoint, $data, $attempt + 1);
            }
            
            throw new Exception("Error en petición SUNAT después de {$this->retryAttempts} intentos: " . $e->getMessage());
        }
    }

    /**
     * Realiza una petición GET a SUNAT con autenticación automática
     * Solo para consultas de estado cuando sea necesario
     */
    public function get(string $endpoint, array $params = [], int $attempt = 1): Response
    {
        try {
            $headers = $this->authService->getAuthHeaders();
            
            $response = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->get($this->buildUrl($endpoint), $params);

            // Si hay error de autenticación, invalidar caché y reintentar
            if (in_array($response->status(), [401, 403]) && $attempt === 1) {
                $this->authService->invalidateToken();
                return $this->get($endpoint, $params, $attempt + 1);
            }

            return $response;

        } catch (Exception $e) {
            // Reintentar en caso de errores de conexión
            if ($attempt < $this->retryAttempts) {
                sleep(2); // Esperar 2 segundos antes de reintentar
                return $this->get($endpoint, $params, $attempt + 1);
            }
            
            throw new Exception("Error en petición SUNAT después de {$this->retryAttempts} intentos: " . $e->getMessage());
        }
    }

    /**
     * Construye la URL completa para GRE Transportista según documentación SUNAT
     */
    protected function buildUrl(string $endpoint): string
    {
        // URL base para GRE según el modo (beta/producción)
        $mode = env('GREENTER_MODE', 'beta');
        $baseUrl = $mode === 'beta' 
            ? 'https://api-cpe.sunat.gob.pe/v1/contribuyente/gem/'
            : 'https://api-cpe.sunat.gob.pe/v1/contribuyente/gem/';
        
        return $baseUrl . ltrim($endpoint, '/');
    }

    /**
     * Construye el endpoint específico para envío de GRE Transportista
     * Extrae serie y número del XML para construir la URL correcta
     * Formato: comprobantes/{numRucEmisor}/{codCpe}-{numSerie}-{numCpe}
     */
    public function buildGreTransportEndpoint(string $xmlContent): string
    {
        // Extraer serie y número del XML
        $serie = $this->extractSerieFromXml($xmlContent);
        $numero = $this->extractNumeroFromXml($xmlContent);
        
        $codCpe = '31'; // Código para GRE Transportista
        
        return "comprobantes/{$this->ruc}-{$codCpe}-{$serie}-{$numero}";
    }

    /**
     * Construye el endpoint para consulta de estado usando el ticket
     * Formato: envios/{numTicket}
     */
    public function buildGreStatusEndpoint(string $ticket): string
    {
        return "comprobantes/envios/{$ticket}";
    }

    /**
     * Extrae la serie del XML de la GRE
     */
    private function extractSerieFromXml(string $xmlContent): string
    {
        // Patrón más flexible para series como VVV1, T001, etc.
        if (preg_match('/<cbc:ID>([A-Z]+\d+)-(\d+)<\/cbc:ID>/', $xmlContent, $matches)) {
            return $matches[1]; // Retorna la serie
        }
        throw new Exception('No se pudo extraer la serie del XML');
    }

    /**
     * Extrae el número del XML de la GRE
     */
    private function extractNumeroFromXml(string $xmlContent): string
    {
        // ✅ CORREGIDO: Usar el mismo patrón flexible que extractSerieFromXml
        if (preg_match('/<cbc:ID>([A-Z]+\d+)-(\d+)<\/cbc:ID>/', $xmlContent, $matches)) {
            return $matches[2]; // Retorna el número
        }
        throw new Exception('No se pudo extraer el número del XML');
    }

    /**
     * Maneja respuestas de error de SUNAT y lanza excepciones descriptivas
     */
    public function handleErrorResponse(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $statusCode = $response->status();
        $errorData = $response->json();

        $errorMessage = "Error SUNAT (Código: {$statusCode})";
        
        if (isset($errorData['message'])) {
            $errorMessage .= ": " . $errorData['message'];
        } elseif (isset($errorData['error'])) {
            $errorMessage .= ": " . $errorData['error'];
        } elseif (isset($errorData['details'])) {
            $errorMessage .= ": " . json_encode($errorData['details']);
        } else {
            $errorMessage .= ": " . $response->body();
        }

        throw new Exception($errorMessage);
    }

    /**
     * Verifica conectividad básica con SUNAT (opcional)
     */
    public function isServiceAvailable(): bool
    {
        try {
            // Solo verificar si podemos obtener un token válido
            $this->authService->getAccessToken();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}