<?php

namespace App\Services\DespatchImport;

use Exception;

class SunatXmlParser
{
    /**
     * Parsea el XML y extrae los datos principales
     */
    public function parseXmlData(string $xmlContent): array
    {
        $xml = simplexml_load_string($xmlContent);

        if ($xml === false) {
            throw new Exception('El archivo XML no es válido');
        }

        // Registrar namespaces
        $xml->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xml->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xml->registerXPathNamespace('da', 'urn:oasis:names:specification:ubl:schema:xsd:DespatchAdvice-2');

        $data = [];

        // Información básica - BUSCAR EL ID PRINCIPAL (primer elemento hijo directo)
        $idElements = $xml->xpath('/da:DespatchAdvice/cbc:ID');
        $idElement = $xml->children('urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2')->ID;

        if (!$idElement) {
            throw new Exception('No se encontró el ID principal de la guía en el XML');
        }

        $idString = (string) $idElement;

        if (!preg_match('/^([A-Z0-9]+)-(\d+)$/', $idString, $matches)) {
            throw new Exception("Formato de ID inválido: {$idString}");
        }

        $data['series'] = $matches[1];
        $data['number'] = (int) $matches[2];

        // Fechas
        $data['emission_date'] = $this->getXmlValue($xml, '//cbc:IssueDate');
        $data['transfer_start_date'] = $this->getXmlValue($xml, '//cac:TransitPeriod/cbc:StartDate');

        // Documentos relacionados
        $data['documentos_relacionados'] = $this->parseDocumentosRelacionados($xml);

        // Observaciones
        $data['observations'] = $this->getXmlValue($xml, '//cbc:Note');

        // Peso
        $weightElement = $xml->xpath('//cbc:GrossWeightMeasure')[0] ?? null;
        if ($weightElement) {
            $data['total_gross_weight'] = (float) $weightElement;
            $data['total_gross_weight_unit_of_measure'] = (string) $weightElement['unitCode'] ?: 'KGM';
        }

        // Transportista (debe ser tu empresa)
        $data['transportista'] = $this->parseTransportista($xml);

        // Remitente y Destinatario
        $data['remitente'] = $this->parseRemitente($xml);
        $data['destinatario'] = $this->parseDestinatario($xml);

        // Conductor principal
        $data['conductor'] = $this->parseConductorPrincipal($xml);

        // Vehículo principal
        $data['vehiculo'] = $this->parseVehiculoPrincipal($xml);

        // Vehículos secundarios
        $data['vehiculos_secundarios'] = $this->parseVehiculosSecundarios($xml);

        // Ubicaciones
        $data['partida'] = $this->parseUbicacionPartida($xml);
        $data['llegada'] = $this->parseUbicacionLlegada($xml);

        return $data;
    }
    /**
     * Obtiene valor de un elemento XML usando XPath
     */
    protected function getXmlValue($xml, string $xpath): ?string
    {
        $elements = $xml->xpath($xpath);
        return $elements && count($elements) > 0 ? (string) $elements[0] : null;
    }
    /**
     * Parsea datos del transportista
     */
    protected function parseTransportista($xml): array
    {
        $ruc = $this->getXmlValue($xml, '//cac:DespatchSupplierParty//cbc:ID');
        $nombre = $this->getXmlValue($xml, '//cac:DespatchSupplierParty//cbc:RegistrationName');

        return [
            'ruc' => $ruc,
            'nombre' => $nombre
        ];
    }
    /**
     * Parsea datos del remitente
     */
    protected function parseRemitente($xml): array
    {
        $ruc = $this->getXmlValue($xml, '//cac:OriginatorCustomerParty//cbc:ID');
        $nombre = $this->getXmlValue($xml, '//cac:OriginatorCustomerParty//cbc:RegistrationName');

        return [
            'ruc' => $ruc,
            'nombre' => $nombre
        ];
    }
    /**
     * Parsea datos del destinatario
     */
    protected function parseDestinatario($xml): array
    {
        $ruc = $this->getXmlValue($xml, '//cac:DeliveryCustomerParty//cbc:ID');
        $nombre = $this->getXmlValue($xml, '//cac:DeliveryCustomerParty//cbc:RegistrationName');

        return [
            'ruc' => $ruc,
            'nombre' => $nombre
        ];
    }
    /**
     * Parsea datos del conductor principal
     */
    protected function parseConductorPrincipal($xml): array
    {
        $conductores = $xml->xpath('//cac:DriverPerson');

        $conductorPrincipal = null;
        foreach ($conductores as $conductor) {
            $jobTitle = (string) $conductor->xpath('cbc:JobTitle')[0] ?? '';
            if ($jobTitle === 'Principal') {
                $conductorPrincipal = $conductor;
                break;
            }
        }

        if (!$conductorPrincipal && !empty($conductores)) {
            $conductorPrincipal = $conductores[0];
        }

        if (!$conductorPrincipal) {
            throw new Exception('No se encontró conductor en el XML');
        }

        $documento = (string) $conductorPrincipal->xpath('cbc:ID')[0] ?? '';
        $tipoDoc = (string) $conductorPrincipal->xpath('cbc:ID/@schemeID')[0] ?? '1';
        $firstName = (string) $conductorPrincipal->xpath('cbc:FirstName')[0] ?? '';
        $lastName = (string) $conductorPrincipal->xpath('cbc:FamilyName')[0] ?? '';
        $licencia = (string) $conductorPrincipal->xpath('cac:IdentityDocumentReference/cbc:ID')[0] ?? '';

        // Procesar nombres correctamente
        $names = $this->processDriverName($firstName, $lastName);

        return [
            'document_number' => $documento,
            'document_type' => $this->mapSunatDocumentType($tipoDoc),
            'first_name' => $names['first_name'],
            'last_name' => $names['last_name'],
            'license_number' => $licencia
        ];
    }
    /**
     * Parsea datos del vehículo principal
     */
    protected function parseVehiculoPrincipal($xml): array
    {
        $placa = $this->getXmlValue($xml, '//cac:TransportEquipment/cbc:ID');
        $certificado = $this->getXmlValue($xml, '//cac:TransportEquipment//cbc:RegistrationNationalityID');

        if (!$placa) {
            throw new Exception('No se encontró placa del vehículo principal en el XML');
        }

        return [
            'plate_number' => $placa,
            'vehicle_certificate' => $certificado
        ];
    }
    /**
     * Parsea vehículos secundarios
     */
    protected function parseVehiculosSecundarios($xml): array
    {
        $secundarios = [];
        $vehiculosSecundarios = $xml->xpath('//cac:AttachedTransportEquipment');

        foreach ($vehiculosSecundarios as $vehiculo) {
            $placa = (string) $vehiculo->xpath('cbc:ID')[0] ?? '';
            $certificado = (string) $vehiculo->xpath('cac:ApplicableTransportMeans/cbc:RegistrationNationalityID')[0] ?? '';

            if ($placa) {
                $secundarios[] = [
                    'plate_number' => $placa,
                    'vehicle_certificate' => $certificado
                ];
            }
        }

        return $secundarios;
    }
    /**
     * Parsea ubicación de partida
     */
    protected function parseUbicacionPartida($xml): array
    {
        $ubigeo = $this->getXmlValue($xml, '//cac:Despatch//cac:DespatchAddress/cbc:ID');
        $direccion = $this->getXmlValue($xml, '//cac:Despatch//cac:DespatchAddress//cbc:Line');

        return [
            'ubigeo' => $ubigeo,
            'address' => $direccion
        ];
    }
    /**
     * Parsea ubicación de llegada
     */
    protected function parseUbicacionLlegada($xml): array
    {
        $ubigeo = $this->getXmlValue($xml, '//cac:DeliveryAddress/cbc:ID');
        $direccion = $this->getXmlValue($xml, '//cac:DeliveryAddress//cbc:Line');

        return [
            'ubigeo' => $ubigeo,
            'address' => $direccion
        ];
    }
    /**
     * Parsea documentos relacionados (AdditionalDocumentReference)
     */
    protected function parseDocumentosRelacionados($xml): array
    {
        $documentos = [];
        $documentElements = $xml->xpath('//cac:AdditionalDocumentReference');

        foreach ($documentElements as $doc) {
            $docId = (string) $doc->xpath('cbc:ID')[0] ?? '';
            $docType = (string) $doc->xpath('cbc:DocumentTypeCode')[0] ?? '';

            // Extraer serie y número del ID (formato: SERIE-NUMERO)
            if (preg_match('/^([A-Z0-9]+)-(\d+)$/', $docId, $matches)) {
                $documentos[] = [
                    'document_type' => $docType,
                    'series' => $matches[1],
                    'number' => (int) $matches[2]
                ];
            }
        }

        return $documentos;
    }
    /**
     * Mapea tipos de documento de SUNAT a nuestro sistema
     */
    protected function mapSunatDocumentType(string $sunatType): string
    {
        return match ($sunatType) {
            '1' => 'DNI',
            '6' => 'RUC',
            '4' => 'CE',
            '7' => 'PASAPORTE',
            default => 'DNI'
        };
    }
    /**
     * Procesa nombres de conductor manejando duplicación de SUNAT
     */
    protected function processDriverName(string $firstName, string $lastName): array
    {
        // Normalizar texto a formato título
        $fullName = $this->normalizeText($firstName);

        // Si firstName y lastName son iguales, procesar como nombre completo
        if (trim($firstName) === trim($lastName) || empty(trim($lastName))) {
            return $this->splitFullName($fullName);
        }

        // Si son diferentes, usar como están pero normalizados
        return [
            'first_name' => $this->normalizeText($firstName),
            'last_name' => $this->normalizeText($lastName)
        ];
    }
    /**
     * Divide un nombre completo en nombres y apellidos
     */
    protected function splitFullName(string $fullName): array
    {
        $parts = array_filter(explode(' ', trim($fullName)));

        if (count($parts) < 2) {
            return [
                'first_name' => $fullName,
                'last_name' => 'Por Completar'
            ];
        }

        // Tomar las primeras 2 palabras como apellidos, el resto como nombres
        if (count($parts) >= 3) {
            $apellidos = implode(' ', array_slice($parts, 0, 2));
            $nombres = implode(' ', array_slice($parts, 2));
        } else {
            // Si solo hay 2 palabras, primera es apellido, segunda es nombre
            $apellidos = $parts[0];
            $nombres = $parts[1];
        }

        return [
            'first_name' => $nombres,
            'last_name' => $apellidos
        ];
    }
    /**
     * Normaliza texto: convierte a formato título y limpia espacios
     */
    protected function normalizeText(string $text): string
    {
        return ucwords(strtolower(trim($text)));
    }
}
