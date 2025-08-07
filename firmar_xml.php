<?php
// Script para firmar XML con certificado digital
// Requiere: composer require greenter/xmldsig

require 'vendor/autoload.php';

use Greenter\XMLSecLibs\Sunat\SignedXml;

// Rutas de archivos
$xmlPath = 'public/certs/20601921023-31-V001-1.xml';        // Ruta a tu XML
$certPath = 'public/certs/CT2507128340.pem';                // Ruta a tu certificado PEM
$outputPath = 'public/certs/20601921023-31-V001-1_firmado.xml'; // XML firmado

try {
    // Crear instancia del firmador
    $signer = new SignedXml();

    // Configurar certificado
    $signer->setCertificateFromFile($certPath);

    // Firmar el XML
    echo "Firmando XML...\n";
    $xmlSigned = $signer->signFromFile($xmlPath);

    // Guardar XML firmado
    file_put_contents($outputPath, $xmlSigned);

    echo "XML firmado correctamente: {$outputPath}\n";

    // Crear ZIP
    $zipPath = '20601921023-31-V001-1.zip';
    $zip = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
        $zip->addFile($outputPath, '20601921023-31-V001-1.xml');
        $zip->close();
        echo "ZIP creado: {$zipPath}\n";

        // Generar Base64
        $base64Content = base64_encode(file_get_contents($zipPath));
        file_put_contents('archivo_base64.txt', $base64Content);
        echo "Base64 generado: archivo_base64.txt\n";

        // Generar Hash SHA-256
        $hashZip = hash_file('sha256', $zipPath);
        echo "Hash SHA-256: {$hashZip}\n";

        // Mostrar JSON para Postman
        echo "\n JSON para Postman:\n";
        echo json_encode([
            'archivo' => [
                'nomArchivo' => '20601921023-31-V001-1.zip',
                'arcGreZip' => $base64Content,
                'hashZip' => $hashZip
            ]
        ], JSON_PRETTY_PRINT);

    } else {
        echo "Error al crear ZIP\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
