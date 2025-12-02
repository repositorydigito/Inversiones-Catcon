<?php

namespace App\Services\DespatchImport;

use App\Models\Despatch;

class DespatchFactory
{
    protected FrequentLocationMatcher $matcher;

    public function __construct(FrequentLocationMatcher $matcher)
    {
        $this->matcher = $matcher;
    }
    /**
     * Crea el despatch con los datos procesados
     */
    public function createDespatch(array $xmlData, array $entities): Despatch
    {
        // Generar códigos de ubigeo para los campos de formulario
        $departureUbigeoData = $this->parseUbigeoCode($xmlData['partida']['ubigeo']);
        $arrivalUbigeoData = $this->parseUbigeoCode($xmlData['llegada']['ubigeo']);

        // Determinar Punto 1 (loading_point) basado en el último Punto 4 (unloading_point)
        // del último viaje del mismo conductor. Si no existe, queda null.
        $previousDespatch = Despatch::where('driver_id', $entities['conductor']->id)
            ->orderBy('emission_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->first();
        $autoLoadingPoint = $previousDespatch?->unloading_point;

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
            // Ahora pasamos también el ubigeo para mejorar el matching
            'departure_location' => $this->matcher->inferLocationPoint(
                $xmlData['partida']['address'],
                $xmlData['partida']['ubigeo']
            ),
            'arrival_location' => $this->matcher->inferLocationPoint(
                $xmlData['llegada']['address'],
                $xmlData['llegada']['ubigeo']
            ),

            // Punto 1 (carga) automatizado desde el último viaje del conductor
            'loading_point' => $autoLoadingPoint,

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
