<?php

namespace App\Services;

use ZipArchive;
use Exception;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class SunatZipService
{
    /**
     * Comprime el XML en un archivo ZIP y lo convierte a Base64
     */
    public function compressAndEncode(string $xmlContent, string $xmlFileName): array
    {
        // Crear directorio temporal si no existe
        $tempDir = storage_path('app/temp/sunat');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $xmlFilePath = $tempDir . '/' . $xmlFileName;
        $zipFilePath = $tempDir . '/' . str_replace('.xml', '.zip', $xmlFileName);

        try {
            // 1. Guardar el XML en archivo temporal
            if (file_put_contents($xmlFilePath, $xmlContent) === false) {
                throw new Exception('No se pudo escribir el archivo XML temporal');
            }

            // 2. Crear el archivo ZIP
            $zip = new ZipArchive();
            $result = $zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            
            if ($result !== TRUE) {
                throw new Exception("No se pudo crear el archivo ZIP. Código de error: {$result}");
            }

            // 3. Agregar el XML al ZIP
            if (!$zip->addFile($xmlFilePath, $xmlFileName)) {
                $zip->close();
                throw new Exception('No se pudo agregar el archivo XML al ZIP');
            }

            $zip->close();

            // 4. Verificar que el ZIP se creó correctamente
            if (!file_exists($zipFilePath) || filesize($zipFilePath) === 0) {
                throw new Exception('El archivo ZIP no se creó correctamente');
            }

            // 5. Leer el contenido del ZIP y convertir a Base64
            $zipContent = file_get_contents($zipFilePath);
            if ($zipContent === false) {
                throw new Exception('No se pudo leer el archivo ZIP creado');
            }

            $base64Content = base64_encode($zipContent);

            // 6. Calcular hash para verificación
            $hashZip = hash('sha256', $zipContent);

            return [
                'xml_file_name' => $xmlFileName,
                'zip_file_name' => basename($zipFilePath),
                'xml_content' => $xmlContent,
                'zip_content' => $zipContent,
                'base64_content' => $base64Content,
                'hash_zip' => $hashZip,
                'xml_size' => strlen($xmlContent),
                'zip_size' => strlen($zipContent),
                'base64_size' => strlen($base64Content),
            ];

        } finally {
            // 7. Limpiar archivos temporales
            $this->cleanupTempFiles($xmlFilePath, $zipFilePath);
        }
    }

    /**
     * Descomprime un archivo ZIP desde Base64 y extrae el XML
     */
    public function decodeAndExtract(string $base64Content): array
    {
        $tempDir = storage_path('app/temp/sunat');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $zipFilePath = $tempDir . '/temp_' . uniqid() . '.zip';

        try {
            // 1. Decodificar Base64 y guardar ZIP
            $zipContent = base64_decode($base64Content);
            if ($zipContent === false) {
                throw new Exception('No se pudo decodificar el contenido Base64');
            }

            if (file_put_contents($zipFilePath, $zipContent) === false) {
                throw new Exception('No se pudo escribir el archivo ZIP temporal');
            }

            // 2. Abrir y extraer el ZIP
            $zip = new ZipArchive();
            if ($zip->open($zipFilePath) !== TRUE) {
                throw new Exception('No se pudo abrir el archivo ZIP');
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

            return [
                'files' => $files,
                'zip_size' => strlen($zipContent),
                'file_count' => count($files),
            ];

        } finally {
            // Limpiar archivo temporal
            if (file_exists($zipFilePath)) {
                unlink($zipFilePath);
            }
        }
    }

    /**
     * Valida que el contenido Base64 sea un ZIP válido
     */
    public function validateBase64Zip(string $base64Content): bool
    {
        try {
            $zipContent = base64_decode($base64Content, true);
            if ($zipContent === false) {
                return false;
            }

            // Verificar firma del ZIP (primeros 4 bytes)
            $zipSignature = substr($zipContent, 0, 4);
            $validSignatures = [
                "\x50\x4B\x03\x04", // ZIP normal
                "\x50\x4B\x05\x06", // ZIP vacío
                "\x50\x4B\x07\x08", // ZIP spanned
            ];

            return in_array($zipSignature, $validSignatures);

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Genera el payload JSON para enviar a SUNAT
     */
    public function generateSunatPayload(string $base64Content, string $xmlFileName): array
    {
        // SUNAT requiere el nombre con extensión .zip
        $archiveName = str_replace('.xml', '.zip', $xmlFileName);

        return [
            'archivo' => [
                'nomArchivo' => $archiveName,
                'arcGreZip' => $base64Content,
                'hashZip' => hash('sha256', base64_decode($base64Content))
            ]
        ];
    }

    /**
     * Limpia archivos temporales
     */
    protected function cleanupTempFiles(string ...$filePaths): void
    {
        foreach ($filePaths as $filePath) {
            if (file_exists($filePath)) {
                try {
                    unlink($filePath);
                } catch (Exception $e) {
                    // Log error but don't throw - cleanup shouldn't break the main process
                    Log::warning("No se pudo eliminar archivo temporal: {$filePath}. Error: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Limpia todos los archivos temporales de SUNAT antiguos
     */
    public function cleanupOldTempFiles(int $olderThanHours = 24): int
    {
        $tempDir = storage_path('app/temp/sunat');
        if (!is_dir($tempDir)) {
            return 0;
        }

        $cleanedCount = 0;
        $cutoffTime = time() - ($olderThanHours * 3600);

        $files = glob($tempDir . '/*');
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoffTime) {
                try {
                    unlink($file);
                    $cleanedCount++;
                } catch (Exception $e) {
                    Log::warning("No se pudo eliminar archivo temporal antiguo: {$file}");
                }
            }
        }

        return $cleanedCount;
    }

    /**
     * Obtiene información sobre el ZIP generado
     */
    public function getZipInfo(string $base64Content): array
    {
        try {
            $zipContent = base64_decode($base64Content);
            
            return [
                'original_size' => strlen($zipContent),
                'base64_size' => strlen($base64Content),
                'compression_ratio' => round((1 - strlen($zipContent) / strlen($base64Content)) * 100, 2),
                'hash_sha256' => hash('sha256', $zipContent),
                'hash_md5' => hash('md5', $zipContent),
                'is_valid_zip' => $this->validateBase64Zip($base64Content),
            ];
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
                'is_valid_zip' => false,
            ];
        }
    }
}