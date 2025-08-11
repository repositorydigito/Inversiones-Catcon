<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Exception;

class SunatAuthService
{
    protected $clientId;
    protected $clientSecret;
    protected $usuarioSol;
    protected $claveSol;
    protected $authUrl;
    protected $scope;

    public function __construct()
    {
        $this->clientId = env('GREENTER_CLIENT_ID');
        $this->clientSecret = env('GREENTER_CLIENT_SECRET');
        $this->usuarioSol =  env('GREENTER_COMPANY_RUC') . env('GREENTER_SOL_USER'); 
        $this->claveSol = env('GREENTER_SOL_PASS');
        
        // URLs de SUNAT según documentación oficial
        $this->authUrl = 'https://api-seguridad.sunat.gob.pe/v1/clientessol/' . $this->clientId . '/oauth2/token/';
        $this->scope = 'https://api-cpe.sunat.gob.pe';
    }

    /**
     * Genera y obtiene el token de acceso de SUNAT
     * Implementa caché para evitar solicitudes innecesarias
     */
    public function getAccessToken(): string
    {
        // Intentar obtener token del caché primero
        $cacheKey = 'sunat_access_token_' . $this->clientId;
        $cachedToken = Cache::get($cacheKey);
        
        if ($cachedToken) {
            return $cachedToken;
        }

        // Si no hay token en caché, generar uno nuevo
        return $this->generateNewToken();
    }

    /**
     * Genera un nuevo token de acceso según documentación SUNAT
     */
    protected function generateNewToken(): string
    {
        try {
            // Headers exactos según documentación SUNAT
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->asForm()
            ->post($this->authUrl, [
                'grant_type' => 'password',
                'scope' => $this->scope,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'username' => $this->usuarioSol,
                'password' => $this->claveSol,
            ]);

            if ($response->failed()) {
                $statusCode = $response->status();
                $errorMessage = $response->body();
                throw new Exception("Error al autenticar con SUNAT. Código: {$statusCode}, Mensaje: {$errorMessage}");
            }

            $tokenData = $response->json();

            if (!isset($tokenData['access_token'])) {
                throw new Exception('SUNAT no devolvió un token de acceso válido: ' . json_encode($tokenData));
            }

            $accessToken = $tokenData['access_token'];
            $expiresIn = $tokenData['expires_in'] ?? 3600; // Por defecto 1 hora

            // Guardar en caché con tiempo de expiración (restamos 5 minutos por seguridad)
            $cacheKey = 'sunat_access_token_' . $this->clientId;
            Cache::put($cacheKey, $accessToken, now()->addSeconds($expiresIn - 300));

            return $accessToken;

        } catch (Exception $e) {
            throw new Exception('Error al generar token SUNAT: ' . $e->getMessage());
        }
    }

    /**
     * Invalida el token en caché (útil si hay errores de autenticación)
     */
    public function invalidateToken(): void
    {
        $cacheKey = 'sunat_access_token_' . $this->clientId;
        Cache::forget($cacheKey);
    }

    /**
     * Verifica si las credenciales están configuradas
     */
    public function hasValidCredentials(): bool
    {
        return !empty($this->clientId) && 
               !empty($this->clientSecret) && 
               !empty($this->usuarioSol) && 
               !empty($this->claveSol);
    }

    /**
     * Obtiene headers de autorización para peticiones a SUNAT
     */
    public function getAuthHeaders(): array
    {
        $token = $this->getAccessToken();
        
        return [
            'Authorization' => 'Bearer ' . $token,            
        ];
    }
}