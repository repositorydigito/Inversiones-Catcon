<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Despatch;
use App\Models\Client;
use App\Models\Vehicle;
use App\Models\Driver;
use Illuminate\Support\Facades\Http;
use Exception;

class DespatchService
{
    protected $apiToken;
    protected $apiUrl; 

    public function __construct()
    {
        $this->apiToken = env('NUBEFACT_API_TOKEN');
        $this->apiUrl = env('NUBEFACT_API_URL');
    }

    public function generateDespatch(Despatch $despatch): array
    {
        // Construye el payload JSON para Nubefact
        $payload = $this->buildDespatchPayload($despatch);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token token="' . $this->apiToken . '"',
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl, $payload);

            if ($response->failed()) {
                $statusCode = $response->status();
                $errorMessage = $response->body();
                throw new Exception("Error al conectar con la API de Nubefact. Código: {$statusCode}, Mensaje: {$errorMessage}");
            }

            $decodedResponse = $response->json();

            // Manejo de errores específicos de Nubefact
            if (isset($decodedResponse['errors'])) {
                $errorDetail = json_encode($decodedResponse['errors']);
                throw new Exception("La API de Nubefact devolvió un error: " . $errorDetail);
            }

            // Actualiza el modelo Despatch con la respuesta de SUNAT
            $despatch->update([
                'accepted_by_sunat'     => $decodedResponse['aceptada_por_sunat'] ?? false,
                'sunat_description'     => $decodedResponse['sunat_description'] ?? null,
                'sunat_note'            => $decodedResponse['sunat_note'] ?? null,
                'sunat_response_code'   => $decodedResponse['sunat_response_code'] ?? null,
                'sunat_soap_error'      => $decodedResponse['sunat_soap_error'] ?? null,
                'qr_code_string'        => $decodedResponse['qr_code_string'] ?? null,
                'enlace_del_pdf'        => $decodedResponse['enlace_del_pdf'] ?? null,
                'enlace_del_xml'        => $decodedResponse['enlace_del_xml'] ?? null,
                'enlace_del_cdr'        => $decodedResponse['enlace_del_cdr'] ?? null,
                //'enlace' enlace del json de respuesta de nubefact
            ]);

            /* if (isset($decodedResponse['aceptada_por_sunat']) && $decodedResponse['aceptada_por_sunat'] === false) {
                throw new Exception("SUNAT rechazó la guía: " . ($decodedResponse['sunat_description'] ?? 'Sin descripción de error.'));
            } */

            return $decodedResponse;

        } catch (Exception $e) {
            // En caso de excepción, registra el error y actualiza el estado de la guía
            $despatch->update([
                'accepted_by_sunat'     => false,
                'sunat_description'     => 'Error al enviar/procesar la guía.',
                'sunat_soap_error'      => $e->getMessage(),
            ]);
            throw $e; // Re-lanza la excepción para que el controlador o la acción de Filament la capturen
        }
    }   

    public function consultDespatchStatus(Despatch $despatch): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Token token="' . $this->apiToken . '"',
            'Content-Type' => 'application/json',
        ])->post($this->apiUrl, [
            'operacion' => 'consultar_guia',
            'tipo_de_comprobante' => $despatch->document_type, // '8' para GRE Transportista //
            'serie' => $despatch->series,
            'numero' => (string)$despatch->number,                       
        ]);        
        
        $decodedResponse = $response->json(); 

        if (isset($decodedResponse['errors'])) { 
            $errorDetail = json_encode($decodedResponse['errors']);
            throw new Exception("Error al consultar el estado de la guía en Nubefact: " . $errorDetail); //
        }

        $despatch->update([ //
            'accepted_by_sunat'     => $decodedResponse['aceptada_por_sunat'] ?? false,
            'sunat_description'     => $decodedResponse['sunat_description'] ?? null,
            'sunat_note'            => $decodedResponse['sunat_note'] ?? null,
            'sunat_response_code'   => $decodedResponse['sunat_responsecode'] ?? null,
            'sunat_soap_error'      => $decodedResponse['sunat_soap_error'] ?? null, //
            'qr_code_string'        => $decodedResponse['cadena_para_codigo_qr'] ?? null,
            'enlace_del_pdf'        => $decodedResponse['enlace_del_pdf'] ?? null,
            'enlace_del_xml'        => $decodedResponse['enlace_del_xml'] ?? null,
            'enlace_del_cdr'        => $decodedResponse['enlace_del_cdr'] ?? null, //
            // 'enlace'                => $decodedResponse['enlace'] ?? null,
        ]);
        return $decodedResponse; 
    }

    protected function buildDespatchPayload(Despatch $despatch): array
    {
        // Cargar las relaciones necesarias para el JSON
        $despatch->loadMissing([
            'company',
            'client',
            'vehicle',
            'driver',
            'items.unitOfMeasure',
            'secondaryVehicles',
            'secondaryDrivers',
            'relatedDocuments'
        ]);

        if (!$despatch->company) {
            throw new Exception("La guía no tiene una empresa (remitente) asociada.");
        }
        if (!$despatch->client) {
            throw new Exception("La guía no tiene un cliente (destinatario) asociado.");
        }
        if ($despatch->items->isEmpty()) {
            throw new Exception("La guía no tiene ítems.");
        }

        $itemsPayload = $despatch->items->map(function ($item) {
            if (!$item->unitOfMeasure) { // Validar que la unidad de medida exista
                throw new Exception("Ítem sin unidad de medida asociada: " . $item->description);
            }

            return [
                "unidad_de_medida" => $item->unitOfMeasure->code,
                "codigo"           => $item->code ?: '',
                "descripcion"      => $item->description,
                "cantidad"         => (int)$item->quantity,
            ];
        })->toArray();

        $payload = [
            "operacion"                        => 'generar_guia',
            "tipo_de_comprobante"              => $despatch->document_type,
            "serie"                            => $despatch->series,
            "numero"                           => (int      )$despatch->number,
            "cliente_tipo_de_documento"        => $despatch->company->document_type ?? '6',
            "cliente_numero_de_documento"      => $despatch->company->ruc,
            "cliente_denominacion"             => $despatch->company->name,
            "cliente_direccion"                => $despatch->company->address ?? '',
            "cliente_email"                    => $despatch->company->email ?? '',
            "fecha_de_emision"                 => $despatch->emission_date->format('d-m-Y'),
            "observaciones"                    => $despatch->observations ?? '',
            "peso_bruto_total"                 => (float    )$despatch->total_gross_weight,
            "peso_bruto_unidad_de_medida"      => $despatch->total_gross_weight_unit_of_measure,
            "fecha_de_inicio_de_traslado"      => $despatch->transfer_start_date->format('d-m-Y'),

            "transportista_placa_numero"       => $despatch->vehicle->plate_number ?? null,
            "conductor_documento_tipo"         => $this->mapDocumentType($despatch->driver->document_type ?? ''),
            "conductor_documento_numero"       => $despatch->driver->document_number ?? null,
            "conductor_nombre"                 => $despatch->driver->first_name ?? null,
            "conductor_apellidos"              => $despatch->driver->last_name ?? null,
            "conductor_numero_licencia"        => $despatch->driver->license_number ?? null,

            "destinatario_documento_tipo"      => $this->mapDocumentType($despatch->client->document_type ?? ''),
            "destinatario_documento_numero"    => $despatch->client->document_number,
            "destinatario_denominacion"        => $despatch->client->name,

            "punto_de_partida_ubigeo"          => $despatch->departure_ubigeo,
            "punto_de_partida_direccion"       => $despatch->departure_address,
            "punto_de_partida_codigo_sunat_establecimiento" => $despatch->departure_sunat_establishment_code ?? '',

            "punto_de_llegada_ubigeo"          => $despatch->arrival_ubigeo,
            "punto_de_llegada_direccion"       => $despatch->arrival_address,
            "punto_de_llegada_codigo_sunat_establecimiento" => $despatch->arrival_sunat_establishment_code ?? '',

            "enviar_automaticamente_al_cliente" => $despatch->send_automatically_to_client ? 'true' : 'false',
            "formato_de_pdf"                   => $despatch->pdf_format ?? '',
            "items"                            => $itemsPayload,
        ];

        if ($despatch->sunat_envio_indicador) {
            $payload['sunat_envio_indicador'] = $despatch->sunat_envio_indicador;
        }

        if ($despatch->sunat_envio_indicador === '02') {
            $payload['subcontratista_documento_tipo'] = (string)$despatch->subcontractor_document_type;
            $payload['subcontratista_documento_numero'] = $despatch->subcontractor_document_number;
            $payload['subcontratista_denominacion'] = $despatch->subcontractor_denomination;
        }

        if ($despatch->sunat_envio_indicador === '03') {
            $payload['pagador_servicio_documento_tipo_identidad'] = (string)$despatch->service_payer_document_type;
            $payload['pagador_servicio_documento_numero_identidad'] = $despatch->service_payer_document_number;
            $payload['pagador_servicio_denominacion'] = $despatch->service_payer_denomination;
        }

        if ($despatch->secondaryVehicles->isNotEmpty()) {
            $payload['vehiculos_secundarios'] = $despatch->secondaryVehicles->map(function ($vehicle) {
                return [
                    'placa_numero' => $vehicle->plate_number,
                    'tuc'          => $vehicle->vehicle_certificate ?? '',
                ];
            })->toArray();
        }

        if ($despatch->secondaryDrivers->isNotEmpty()) {
            $payload['conductores_secundarios'] = $despatch->secondaryDrivers->map(function ($driver) {
                return [
                    'documento_tipo'    => $driver->document_type,
                    'documento_numero'  => $driver->document_number,
                    'nombre'            => $driver->name,
                    'apellidos'         => $driver->last_name,
                    'numero_licencia'   => $driver->license_number,
                ];
            })->toArray();
        }

        if ($despatch->relatedDocuments->isNotEmpty()) {
            $payload['documentos_relacionados'] = $despatcph->relatedDocuments->map(function ($doc) {
                return [
                    'tipo_documento' => $doc->document_type,
                    'serie'          => $doc->series,
                    'numero'         => (string)$doc->number,
                ];
            })->toArray();
        }

        return $payload;
    }

    protected function mapDocumentType($type): string
    {
        return match (strtoupper($type)) {
            'DNI' => '1',
            'RUC' => '6',
            'CE'  => '4',
            'Pasaporte' => '7',
            default => '0', 
        };
    }
}