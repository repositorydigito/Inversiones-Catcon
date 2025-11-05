<?php

namespace App\Services;

use App\Models\Despatch;
use App\Models\Client;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Models\Company;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SunatXmlImportService
{
    public function importMultipleFromXml(array $xmlFiles): array
    {
        set_time_limit(300);

        $results = [
            'success' => true,
            'total' => count($xmlFiles),
            'imported' => 0,
            'failed' => 0,
            'details' => [],
            'created_entities' => []
        ];

        foreach ($xmlFiles as $index => $xmlContent) {
            try {
                Log::info("Procesando XML " . ($index + 1) . " de " . count($xmlFiles));

                $result = $this->importFromXml($xmlContent);

                if ($result['success']) {
                    $results['imported']++;
                    $results['details'][] = [
                        'status' => 'success',
                        'serie_numero' => $result['despatch']->series . '-' . $result['despatch']->number,
                        'message' => 'Importado exitosamente'
                    ];

                    if (!empty($result['created_entities'])) {
                        $results['created_entities'] = array_merge(
                            $results['created_entities'],
                            $result['created_entities']
                        );
                    }
                } else {
                    $results['failed']++;
                    $results['details'][] = [
                        'status' => 'error',
                        'message' => $result['error']
                    ];
                }

            } catch (Exception $e) {
                $results['failed']++;
                $results['details'][] = [
                    'status' => 'error',
                    'message' => 'Error: ' . $e->getMessage()
                ];
            }
        }

        // Recalcular viáticos después de importar todas las guías
        if ($results['imported'] > 0) {
            $this->recalculateTravelAllowancesAfterImport();
        }

        $results['created_entities'] = array_unique($results['created_entities']);
        $results['success'] = $results['imported'] > 0;

        return $results;
    }
    /**
     * Recalcula viáticos después de una importación masiva
     */
    protected function recalculateTravelAllowancesAfterImport(): void
    {
        // Obtener todas las guías importadas en los últimos 5 minutos
        $recentDespatches = Despatch::where('sunat_description', 'Importado desde XML de SUNAT')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->whereNotNull('driver_id')
            ->orderBy('driver_id')
            ->orderBy('emission_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        // Agrupar por conductor y fecha
        $grouped = $recentDespatches->groupBy(function ($d) {
            return $d->driver_id . '-' . $d->emission_date;
        });

        foreach ($grouped as $group) {
            foreach ($group->values() as $index => $despatch) {
                $dailyTravelAllowance = 30.00;

                // Buscar configuración si existe
                if ($despatch->loading_point && $despatch->departure_location &&
                    $despatch->arrival_location && $despatch->unloading_point) {

                    $config = $this->findOperationalConfig(
                        $despatch->loading_point,
                        $despatch->departure_location,
                        $despatch->arrival_location,
                        $despatch->unloading_point
                    );

                    if ($config && $config->travel_allowances > 0) {
                        $dailyTravelAllowance = $config->travel_allowances;
                    }
                }

                // Solo la primera guía del día debe tener viáticos
                $newTravelAllowances = ($index === 0) ? $dailyTravelAllowance : 0;

                if ($despatch->travel_allowances != $newTravelAllowances) {
                    $despatch->updateQuietly(['travel_allowances' => $newTravelAllowances]);
                    // Forzar creación de gastos operativos
                    $despatch->save();
                }
            }
        }
    }
    /**
     * Importa una guía de remisión desde XML de SUNAT
     */
    public function importFromXml(string $xmlContent): array
    {
        DB::beginTransaction();

        try {
            Log::info("Iniciando importación de XML de SUNAT");

            // 1. Parsear y validar XML
            $xmlData = $this->parseXmlData($xmlContent);

            // 2. Validar duplicados
            $this->validateDuplicate($xmlData);

            // 3. Procesar entidades relacionadas
            $entities = $this->processRelatedEntities($xmlData);

            // 4. Crear el Despatch
            $despatch = $this->createDespatch($xmlData, $entities);

            DB::commit();

            Log::info("XML importado exitosamente", [
                'despatch_id' => $despatch->id,
                'serie_numero' => $despatch->series . '-' . $despatch->number
            ]);

            return [
                'success' => true,
                'despatch' => $despatch,
                'message' => 'Guía importada exitosamente',
                'created_entities' => $entities['created_entities'] ?? []
            ];

        } catch (Exception $e) {
            DB::rollBack();

            Log::error("Error al importar XML", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Error al importar la guía desde XML'
            ];
        }
    }
    /**
     * Parsea el XML y extrae los datos principales
     */
    protected function parseXmlData(string $xmlContent): array
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
     * Valida que no sea duplicado
     */
    protected function validateDuplicate(array $xmlData): void
    {
        $exists = Despatch::where('series', $xmlData['series'])
                          ->where('number', $xmlData['number'])
                          ->where('company_id', 1) // Tu empresa
                          ->exists();

        if ($exists) {
            throw new Exception("Ya existe una guía con serie {$xmlData['series']} y número {$xmlData['number']}");
        }
    }
    /**
     * Procesa todas las entidades relacionadas
     */
    protected function processRelatedEntities(array $xmlData): array
    {
        $entities = ['created_entities' => []];

        // 1. Validar transportista (debe ser tu empresa)
        $company = Company::where('ruc', $xmlData['transportista']['ruc'])->first();
        if (!$company) {
            throw new Exception("El transportista {$xmlData['transportista']['ruc']} no corresponde a tu empresa");
        }
        $entities['company'] = $company;

        // 2. Procesar remitente
        $entities['remitente'] = $this->findOrCreateClient($xmlData['remitente']);
        if ($entities['remitente']->wasRecentlyCreated) {
            $entities['created_entities'][] = "Cliente remitente: {$entities['remitente']->name}";
        }

        // 3. Procesar destinatario
        $entities['destinatario'] = $this->findOrCreateClient($xmlData['destinatario']);
        if ($entities['destinatario']->wasRecentlyCreated) {
            $entities['created_entities'][] = "Cliente destinatario: {$entities['destinatario']->name}";
        }

        // 4. Procesar conductor
        $entities['conductor'] = $this->findOrCreateDriver($xmlData['conductor']);
        if ($entities['conductor']->wasRecentlyCreated) {
            $entities['created_entities'][] = "Conductor: {$entities['conductor']->first_name} {$entities['conductor']->last_name}";
        }

        // 5. Procesar vehículo principal
        $entities['vehiculo'] = $this->findOrCreateVehicle($xmlData['vehiculo'], $entities['conductor']);
        if ($entities['vehiculo']->wasRecentlyCreated) {
            $entities['created_entities'][] = "Vehículo principal: {$entities['vehiculo']->plate_number}";
        }

        // 6. Procesar vehículos secundarios
        $entities['vehiculos_secundarios'] = [];
        foreach ($xmlData['vehiculos_secundarios'] as $vehiculoData) {
            // Pasar el conductor principal también a los secundarios
            $vehiculo = $this->findOrCreateVehicle($vehiculoData, $entities['conductor']);
            $entities['vehiculos_secundarios'][] = $vehiculo;

            if ($vehiculo->wasRecentlyCreated) {
                $entities['created_entities'][] = "Vehículo secundario: {$vehiculo->plate_number}";
            }
        }

        return $entities;
    }
    /**
     * Busca o crea un cliente
     */
    protected function findOrCreateClient(array $clientData): Client
    {
        return Client::firstOrCreate(
            ['document_number' => $clientData['ruc']],
            [
                'name' => $clientData['nombre'],
                'document_type' => 'RUC'
            ]
        );
    }
    /**
     * Busca o crea un conductor
     */
    protected function findOrCreateDriver(array $driverData): Driver
    {
        // Buscar por documento primero (insensible a mayúsculas)
        $existingDriver = Driver::where('document_number', $driverData['document_number'])->first();

        if ($existingDriver) {
            // Si existe, actualizar nombres si están mejor formateados
            $existingDriver->update([
                'first_name' => $driverData['first_name'],
                'last_name' => $driverData['last_name'],
                'license_number' => $driverData['license_number']
            ]);

            return $existingDriver;
        }

        // Si no existe, crear nuevo
        return Driver::create([
            'first_name' => $driverData['first_name'],
            'last_name' => $driverData['last_name'],
            'document_type' => $driverData['document_type'],
            'document_number' => $driverData['document_number'],
            'license_number' => $driverData['license_number']
        ]);
    }
    /**
     * Busca o crea un vehículo
     */
    protected function findOrCreateVehicle(array $vehicleData, Driver $driver = null): Vehicle
    {
        $vehicle = Vehicle::firstOrCreate(
            ['plate_number' => $vehicleData['plate_number']],
            [
                'brand' => 'Por Editar',
                'model' => 'Por Editar',
                'vehicle_certificate' => $vehicleData['vehicle_certificate'] ?? null,
                'driver_id' => $driver?->id
            ]
        );

        // Si existe y no tiene conductor asignado, asignar el conductor
        if (!$vehicle->wasRecentlyCreated && !$vehicle->driver_id && $driver) {
            $vehicle->update(['driver_id' => $driver->id]);
        }

        return $vehicle;
    }
    /**
     * Crea el despatch con los datos procesados
     */
    protected function createDespatch(array $xmlData, array $entities): Despatch
    {
        // Generar códigos de ubigeo para los campos de formulario
        $departureUbigeoData = $this->parseUbigeoCode($xmlData['partida']['ubigeo']);
        $arrivalUbigeoData = $this->parseUbigeoCode($xmlData['llegada']['ubigeo']);

        $despatch = Despatch::create([
            // Datos básicos
            'document_type' => 8, // GRE Transportista
            'series' => $xmlData['series'],
            'number' => $xmlData['number'],
            'company_id' => 1,

            // Fechas
            'emission_date' => $xmlData['emission_date'],
            'transfer_start_date' => $xmlData['transfer_start_date'],

            // Entidades relacionadas
            'sender_client_id' => $entities['remitente']->id,
            'client_id' => $entities['destinatario']->id,
            'driver_id' => $entities['conductor']->id,
            'vehicle_id' => $entities['vehiculo']->id,

            // Ubicaciones
            'departure_ubigeo' => $xmlData['partida']['ubigeo'],
            'departure_address' => $xmlData['partida']['address'],
            'arrival_ubigeo' => $xmlData['llegada']['ubigeo'],
            'arrival_address' => $xmlData['llegada']['address'],

            // Inferir departure_location y arrival_location desde frequent_locations
            'departure_location' => $this->inferLocationPoint($xmlData['partida']['address']),
            'arrival_location' => $this->inferLocationPoint($xmlData['llegada']['address']),

            // Documentos de remitente y destinatario
            'sender_document_number' => $entities['remitente']->document_number,
            'client_document_number' => $entities['destinatario']->document_number,

            // Ubigeo de partida descompuesto
            'departure_departamento' => $departureUbigeoData['departamento'],
            'departure_provincia' => $departureUbigeoData['provincia'],
            'departure_distrito' => $departureUbigeoData['distrito'],

            // Ubigeo de llegada descompuesto
            'arrival_departamento' => $arrivalUbigeoData['departamento'],
            'arrival_provincia' => $arrivalUbigeoData['provincia'],
            'arrival_distrito' => $arrivalUbigeoData['distrito'],

            // Peso
            'total_gross_weight' => $xmlData['total_gross_weight'] ?? 0,
            'total_gross_weight_unit_of_measure' => $xmlData['total_gross_weight_unit_of_measure'] ?? 'KGM',

            // Observaciones
            'observations' => $xmlData['observations'],
            'product' => $xmlData['observations'],

            // Indicador de envío SUNAT
            'sunat_envio_indicador' => '01',

            // Estado SUNAT
            'accepted_by_sunat' => true,
            'sunat_description' => 'Importado desde XML de SUNAT',
            'sunat_response_code' => '0',

            // Gastos operativos (valores por defecto)
            'tolls' => 0,
            'loading_expenses' => 0,
            'travel_allowances' => 0,
            'variable_salary' => 0,
            'operations_manager' => 0,
            'security' => 0,
        ]);

        // Crear documentos relacionados
        foreach ($xmlData['documentos_relacionados'] as $docData) {
            $despatch->relatedDocuments()->create($docData);
        }

        // Vincular vehículos secundarios
        if (!empty($entities['vehiculos_secundarios'])) {
            $vehicleIds = collect($entities['vehiculos_secundarios'])->pluck('id')->toArray();
            $despatch->secondaryVehicles()->attach($vehicleIds);
        }

        return $despatch;
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
     * Normaliza una cadena para comparación flexible
     * Elimina puntos, comas, guiones, espacios extras y convierte a minúsculas
     */
    protected function normalizeForComparison(string $text): string
    {
        // Convertir a minúsculas
        $text = strtolower($text);

        // Eliminar palabras comunes que no aportan (como S/N, REF, KM, etc.)
        $commonWords = ['s/n', 'ref:', 'referencia:', 'km', 'km.', 'alt', 'altura'];
        foreach ($commonWords as $word) {
            $text = str_replace($word, '', $text);
        }

        // Eliminar puntos, comas, guiones y caracteres especiales
        $text = preg_replace('/[.,\-()\/]/', ' ', $text);

        // Eliminar tildes/acentos
        $text = $this->removeAccents($text);

        // Reemplazar múltiples espacios por uno solo
        $text = preg_replace('/\s+/', ' ', $text);

        // Trim final
        return trim($text);
    }
    /**
     * Elimina tildes y acentos de una cadena
     */
    protected function removeAccents(string $text): string
    {
        $unwanted = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
            'ñ' => 'n', 'Ñ' => 'n'
        ];

        return strtr($text, $unwanted);
    }
    /**
     * Busca configuración con normalización flexible y similitud
     */
    protected function findOperationalConfig($loadingPoint, $departureLocation, $arrivalLocation, $unloadingPoint)
    {
        if (!$loadingPoint || !$departureLocation || !$arrivalLocation || !$unloadingPoint) {
            return null;
        }

        // Normalizar los valores de entrada
        $normalizedInput = [
            'loading_point' => $this->normalizeForComparison($loadingPoint),
            'departure_location' => $this->normalizeForComparison($departureLocation),
            'arrival_location' => $this->normalizeForComparison($arrivalLocation),
            'unloading_point' => $this->normalizeForComparison($unloadingPoint),
        ];

        // Umbral de similitud (85% = bastante flexible, 90% = más estricto)
        $threshold = 85;

        // Buscar coincidencia exacta primero
        $exactMatch = \App\Models\OperationalExpenseConfig::all()
            ->first(function ($config) use ($normalizedInput) {
                return $this->normalizeForComparison($config->departure_point) === $normalizedInput['loading_point']
                    && $this->normalizeForComparison($config->departure_location) === $normalizedInput['departure_location']
                    && $this->normalizeForComparison($config->arrival_location) === $normalizedInput['arrival_location']
                    && $this->normalizeForComparison($config->destination_point) === $normalizedInput['unloading_point'];
            });

        if ($exactMatch) {
            return $exactMatch;
        }

        // Si no hay coincidencia exacta, buscar por similitud
        $bestMatch = null;
        $bestScore = 0;

        foreach (\App\Models\OperationalExpenseConfig::all() as $config) {
            $score = $this->calculateSimilarityScore($config, $normalizedInput);

            if ($score >= $threshold && $score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $config;
            }
        }

        return $bestMatch;
    }

    /**
     * Calcula un score de similitud entre la configuración y los valores de entrada
     * Retorna un porcentaje de 0 a 100
     */
    protected function calculateSimilarityScore($config, array $normalizedInput): float
    {
        $scores = [];

        // Comparar cada campo
        $scores[] = $this->stringSimilarity(
            $this->normalizeForComparison($config->departure_point),
            $normalizedInput['loading_point']
        );

        $scores[] = $this->stringSimilarity(
            $this->normalizeForComparison($config->departure_location),
            $normalizedInput['departure_location']
        );

        $scores[] = $this->stringSimilarity(
            $this->normalizeForComparison($config->arrival_location),
            $normalizedInput['arrival_location']
        );

        $scores[] = $this->stringSimilarity(
            $this->normalizeForComparison($config->destination_point),
            $normalizedInput['unloading_point']
        );

        // Retornar el promedio de similitud de todos los campos
        return array_sum($scores) / count($scores);
    }

    /**
     * Calcula similitud entre dos strings (0-100)
     */
    protected function stringSimilarity(string $str1, string $str2): float
    {
        // Si son exactamente iguales, 100%
        if ($str1 === $str2) {
            return 100;
        }

        // Si alguno está vacío, 0%
        if (empty($str1) || empty($str2)) {
            return 0;
        }

        // Usar similar_text que es más rápido que levenshtein
        similar_text($str1, $str2, $percent);

        return $percent;
    }
    /**
     * Infiere el punto de ruta (point) basándose en una dirección
     * Usa normalización flexible y similitud para encontrar coincidencias
     */
    protected function inferLocationPoint(?string $address): ?string
    {
        if (!$address) {
            return null;
        }

        // Normalizar la dirección de entrada
        $normalizedAddress = $this->normalizeForComparison($address);

        // Buscar coincidencia exacta primero (normalizada)
        $exactMatch = \App\Models\FrequentLocation::where('is_active', true)
            ->get()
            ->first(function ($location) use ($normalizedAddress) {
                return $this->normalizeForComparison($location->name) === $normalizedAddress;
            });

        if ($exactMatch) {
            return $exactMatch->point;
        }

        // Si no hay coincidencia exacta, buscar por similitud (umbral 85%)
        $threshold = 85;
        $bestMatch = null;
        $bestScore = 0;

        foreach (\App\Models\FrequentLocation::where('is_active', true)->get() as $location) {
            $normalizedLocationName = $this->normalizeForComparison($location->name);

            similar_text($normalizedAddress, $normalizedLocationName, $percent);

            if ($percent >= $threshold && $percent > $bestScore) {
                $bestScore = $percent;
                $bestMatch = $location;
            }
        }

        return $bestMatch?->point;
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
    /**
     * Descompone un código de ubigeo en departamento, provincia y distrito
     */
    protected function parseUbigeoCode(string $ubigeoCode): array
    {
        if (strlen($ubigeoCode) !== 6) {
            return [
                'departamento' => null,
                'provincia' => null,
                'distrito' => null
            ];
        }

        return [
            'departamento' => substr($ubigeoCode, 0, 2),
            'provincia' => substr($ubigeoCode, 2, 2),
            'distrito' => substr($ubigeoCode, 4, 2)
        ];
    }
}
