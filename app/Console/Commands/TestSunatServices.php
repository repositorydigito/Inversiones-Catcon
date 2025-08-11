<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SunatAuthService;
use App\Services\SunatXmlGenerator;
use App\Services\SunatZipService;
use App\Services\SunatHttpClient;
use App\Services\SunatDespatchService;
use App\Models\Despatch;
use Exception;

class TestSunatServices extends Command
{    
    protected $signature = 'test:sunat-services {--step=all}';
    protected $description = 'Prueba los servicios de SUNAT paso a paso';
    
    public function handle()
    {
        $step = $this->option('step');

        $this->info("🧪 Iniciando pruebas de servicios SUNAT...\n");

        switch ($step) {
            case 'auth':
                $this->testAuth();
                break;
            case 'xml':
                $this->testXmlGeneration();
                break;
            case 'zip':
                $this->testZipService();
                break;
            case 'full':
                $this->testFullFlow();
                break;
            default:
                $this->testAll();
        }
    }

    private function testAll()
    {
        $this->testServiceResolution();
        $this->testAuth();
        $this->testXmlGeneration();
        $this->testZipService();
    }

    private function testServiceResolution()
    {
        $this->info("1️⃣ Probando resolución de servicios...");

        try {
            $authService = app(SunatAuthService::class);
            $this->info("✅ SunatAuthService: OK");

            $xmlGenerator = app(SunatXmlGenerator::class);
            $this->info("✅ SunatXmlGenerator: OK");

            $zipService = app(SunatZipService::class);
            $this->info("✅ SunatZipService: OK");

            $httpClient = app(SunatHttpClient::class);
            $this->info("✅ SunatHttpClient: OK");

            $despatchService = app(SunatDespatchService::class);
            $this->info("✅ SunatDespatchService: OK");

        } catch (Exception $e) {
            $this->error("❌ Error en resolución de servicios: " . $e->getMessage());
            return false;
        }

        $this->info("✅ Todos los servicios se resuelven correctamente\n");
        return true;
    }

    private function testAuth()
    {
        $this->info("2️⃣ Probando autenticación con SUNAT...");

        try {
            $authService = app(SunatAuthService::class);

            // Verificar credenciales
            if (!$authService->hasValidCredentials()) {
                $this->error("❌ Credenciales no configuradas en .env");
                return false;
            }

            $this->info("✅ Credenciales configuradas");

            // Intentar obtener token
            $token = $authService->getAccessToken();
            $this->info("✅ Token obtenido: " . substr($token, 0, 20) . "...");

        } catch (Exception $e) {
            $this->error("❌ Error en autenticación: " . $e->getMessage());
            return false;
        }

        $this->info("✅ Autenticación exitosa\n");
        return true;
    }

    private function testXmlGeneration()
    {
        $this->info("3️⃣ Probando generación de XML...");

        try {
            // Buscar una guía existente para probar
            $despatch = Despatch::with(['company', 'client', 'vehicle', 'driver', 'items.unitOfMeasure'])
                              ->first();

            if (!$despatch) {
                $this->error("❌ No hay guías en la base de datos para probar");
                return false;
            }

            $xmlGenerator = app(SunatXmlGenerator::class);
            
            // Generar XML
            $xml = $xmlGenerator->generateXml($despatch);
            $fileName = $xmlGenerator->generateXmlFileName($despatch);

            $this->info("✅ XML generado para: {$despatch->series}-{$despatch->number}");
            $this->info("✅ Archivo: {$fileName}");
            $this->info("✅ Tamaño XML: " . number_format(strlen($xml)) . " bytes");

            // Validar XML
            $xmlGenerator->validateXml($xml);
            $this->info("✅ XML válido");

            // Mostrar fragmento del XML
            $this->info("📄 Fragmento del XML:");
            $this->line(substr($xml, 0, 200) . "...");

        } catch (Exception $e) {
            $this->error("❌ Error generando XML: " . $e->getMessage());
            return false;
        }

        $this->info("✅ Generación de XML exitosa\n");
        return true;
    }

    private function testZipService()
    {
        $this->info("4️⃣ Probando servicio ZIP...");

        try {
            $zipService = app(SunatZipService::class);
            
            // Crear XML de prueba
            $testXml = '<?xml version="1.0" encoding="UTF-8"?><test>Prueba ZIP</test>';
            $fileName = '20601921023-31-VVV1-999.xml';

            // Comprimir y codificar
            $zipData = $zipService->compressAndEncode($testXml, $fileName);

            $this->info("✅ ZIP creado");
            $this->info("✅ Tamaño original: " . $zipData['xml_size'] . " bytes");
            $this->info("✅ Tamaño ZIP: " . $zipData['zip_size'] . " bytes");
            $this->info("✅ Tamaño Base64: " . $zipData['base64_size'] . " bytes");
            $this->info("✅ Hash: " . substr($zipData['hash_zip'], 0, 16) . "...");

            // Validar ZIP
            $isValid = $zipService->validateBase64Zip($zipData['base64_content']);
            $this->info($isValid ? "✅ ZIP Base64 válido" : "❌ ZIP Base64 inválido");

        } catch (Exception $e) {
            $this->error("❌ Error en servicio ZIP: " . $e->getMessage());
            return false;
        }

        $this->info("✅ Servicio ZIP exitoso\n");
        return true;
    }

    private function testFullFlow()
    {
        $this->info("5️⃣ Probando flujo completo (SIN ENVÍO A SUNAT)...");

        try {
            $despatch = Despatch::with(['company', 'client', 'vehicle', 'driver', 'items.unitOfMeasure', 'secondaryVehicles'])
                              ->first();

            if (!$despatch) {
                $this->error("❌ No hay guías para probar");
                return false;
            }

            $sunatService = app(SunatDespatchService::class);

            // Validar guía
            $validation = $sunatService->validateDespatchForSending($despatch);
            
            if (!$validation['is_valid']) {
                $this->error("❌ Guía no válida para envío:");
                foreach ($validation['errors'] as $error) {
                    $this->error("   • {$error}");
                }
                return false;
            }

            $this->info("✅ Guía válida para envío");

            if (!empty($validation['warnings'])) {
                $this->warn("⚠️ Advertencias:");
                foreach ($validation['warnings'] as $warning) {
                    $this->warn("   • {$warning}");
                }
            }

            // Generar XML y ZIP (sin enviar)
            $xmlGenerator = app(SunatXmlGenerator::class);
            $zipService = app(SunatZipService::class);
            $httpClient = app(SunatHttpClient::class);

            $xml = $xmlGenerator->generateXml($despatch);
            $fileName = $xmlGenerator->generateXmlFileName($despatch);
            $zipData = $zipService->compressAndEncode($xml, $fileName);
            $endpoint = $httpClient->buildGreTransportEndpoint($xml);
            $payload = $zipService->generateSunatPayload($zipData['base64_content'], $fileName);

            $this->info("✅ XML generado: {$fileName}");
            $this->info("✅ ZIP creado y codificado");
            $this->info("✅ Endpoint construido: {$endpoint}");
            $this->info("✅ Payload preparado (" . number_format(strlen(json_encode($payload))) . " bytes)");

        } catch (Exception $e) {
            $this->error("❌ Error en flujo completo: " . $e->getMessage());
            return false;
        }

        $this->info("✅ Flujo completo exitoso (preparado para envío)\n");
        return true;
    }
}
