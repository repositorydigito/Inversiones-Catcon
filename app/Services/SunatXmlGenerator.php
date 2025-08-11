<?php

namespace App\Services;

use App\Models\Despatch;
use Exception;
use Carbon\Carbon;

class SunatXmlGenerator
{
    protected $xmlTemplate = '<?xml version="1.0" encoding="UTF-8"?>
<DespatchAdvice xmlns:ds="http://www.w3.org/2000/09/xmldsig#" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:qdt="urn:oasis:names:specification:ubl:schema:xsd:QualifiedDatatypes-2" xmlns:sac="urn:sunat:names:specification:ubl:peru:schema:xsd:SunatAggregateComponents-1" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2" xmlns:udt="urn:un:unece:uncefact:data:specification:UnqualifiedDataTypesSchemaModule:2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ccts="urn:un:unece:uncefact:documentation:2" xmlns="urn:oasis:names:specification:ubl:schema:xsd:DespatchAdvice-2">
  <cbc:UBLVersionID>2.1</cbc:UBLVersionID>
  <cbc:CustomizationID>2.0</cbc:CustomizationID>
  <cbc:ID>{{SERIE}}-{{NUMERO}}</cbc:ID>
  <cbc:IssueDate>{{FECHA_EMISION}}</cbc:IssueDate>
  <cbc:IssueTime>{{HORA_EMISION}}</cbc:IssueTime>
  <cbc:DespatchAdviceTypeCode>31</cbc:DespatchAdviceTypeCode>
  {{OBSERVACIONES_BLOCK}}
  <cac:Signature>
    <cbc:ID>{{TRANSPORTISTA_RUC}}</cbc:ID>
    <cac:SignatoryParty>
      <cac:PartyIdentification>
        <cbc:ID>{{TRANSPORTISTA_RUC}}</cbc:ID>
      </cac:PartyIdentification>
      <cac:PartyName>
        <cbc:Name>{{TRANSPORTISTA_NOMBRE}}</cbc:Name>
      </cac:PartyName>
    </cac:SignatoryParty>
    <cac:DigitalSignatureAttachment>
      <cac:ExternalReference>
        <cbc:URI>{{TRANSPORTISTA_RUC}}</cbc:URI>
      </cac:ExternalReference>
    </cac:DigitalSignatureAttachment>
  </cac:Signature>

  <!-- DATOS DEL EMISOR (TRANSPORTISTA = REMITENTE DEL FORMULARIO) -->
  <cac:DespatchSupplierParty>
    <cac:Party>
      <cac:PartyIdentification>
        <cbc:ID schemeID="{{REMITENTE_TIPO_DOC}}" schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">{{REMITENTE_RUC}}</cbc:ID>
      </cac:PartyIdentification>
      <cac:PartyLegalEntity>
        <cbc:RegistrationName>{{REMITENTE_NOMBRE}}</cbc:RegistrationName>
      </cac:PartyLegalEntity>
    </cac:Party>
  </cac:DespatchSupplierParty>
  
  <!-- DATOS DEL RECEPTOR (DESTINATARIO DEL FORMULARIO) -->
  <cac:DeliveryCustomerParty>
    <cac:Party>
      <cac:PartyIdentification>
        <cbc:ID schemeID="{{DESTINATARIO_TIPO_DOC}}" schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">{{DESTINATARIO_RUC}}</cbc:ID>
      </cac:PartyIdentification>
      <cac:PartyLegalEntity>
        <cbc:RegistrationName>{{DESTINATARIO_NOMBRE}}</cbc:RegistrationName>
      </cac:PartyLegalEntity>
    </cac:Party>
  </cac:DeliveryCustomerParty>
  
  <!-- DATOS DE QUIEN PAGA EL SERVICIO (MISMO QUE DESTINATARIO) -->
  <cac:OriginatorCustomerParty>
    <cac:Party>
      <cac:PartyIdentification>
        <cbc:ID schemeID="{{DESTINATARIO_TIPO_DOC}}" schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">{{DESTINATARIO_RUC}}</cbc:ID>
      </cac:PartyIdentification>
      <cac:PartyLegalEntity>
        <cbc:RegistrationName>{{DESTINATARIO_NOMBRE}}</cbc:RegistrationName>
      </cac:PartyLegalEntity>
    </cac:Party>
  </cac:OriginatorCustomerParty>

  <cac:Shipment>
    <cbc:ID>SUNAT_Envio</cbc:ID>
    <cbc:GrossWeightMeasure unitCode="{{PESO_UNIDAD}}">{{PESO_BRUTO}}</cbc:GrossWeightMeasure>
    <cac:ShipmentStage>
      <cac:TransitPeriod>
        <cbc:StartDate>{{FECHA_INICIO_TRASLADO}}</cbc:StartDate>
      </cac:TransitPeriod>
      <cac:CarrierParty>
        <cac:PartyIdentification>
          <cbc:ID schemeID="{{TRANSPORTISTA_TIPO_DOC}}">{{TRANSPORTISTA_RUC}}</cbc:ID>
        </cac:PartyIdentification>
        <cac:PartyLegalEntity>
          <cbc:RegistrationName>{{TRANSPORTISTA_NOMBRE}}</cbc:RegistrationName>
        </cac:PartyLegalEntity>
      </cac:CarrierParty>
      <cac:TransportMeans>
        <cac:RoadTransport>
          <cbc:LicensePlateID>{{VEHICULO_PLACA}}</cbc:LicensePlateID>
        </cac:RoadTransport>
      </cac:TransportMeans>
      <cac:DriverPerson>
        <cbc:ID schemeID="{{CONDUCTOR_TIPO_DOC}}">{{CONDUCTOR_DOC}}</cbc:ID>
        <cbc:FirstName>{{CONDUCTOR_NOMBRES}}</cbc:FirstName>
        <cbc:FamilyName>{{CONDUCTOR_APELLIDOS}}</cbc:FamilyName>
        <cbc:JobTitle>Principal</cbc:JobTitle>
        <cac:IdentityDocumentReference>
          <cbc:ID>{{LICENCIA_CONDUCIR}}</cbc:ID>
        </cac:IdentityDocumentReference>
      </cac:DriverPerson>
      {{CONDUCTORES_SECUNDARIOS_BLOCK}}
    </cac:ShipmentStage>
    <cac:Delivery>
      <cac:DeliveryAddress>
        <cbc:ID schemeAgencyName="PE:INEI" schemeName="Ubigeos">{{DESTINO_UBIGEO}}</cbc:ID>
        <cac:AddressLine>
          <cbc:Line>{{DESTINO_DIRECCION}}</cbc:Line>
        </cac:AddressLine>
      </cac:DeliveryAddress>
      <cac:Despatch>
        <cac:DespatchAddress>
          <cbc:ID schemeAgencyName="PE:INEI" schemeName="Ubigeos">{{ORIGEN_UBIGEO}}</cbc:ID>
          <cac:AddressLine>
            <cbc:Line>{{ORIGEN_DIRECCION}}</cbc:Line>
          </cac:AddressLine>
        </cac:DespatchAddress>
        <cac:DespatchParty>
          <cac:PartyIdentification>
            <cbc:ID schemeID="{{DESTINATARIO_TIPO_DOC}}" schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">{{DESTINATARIO_RUC}}</cbc:ID>
          </cac:PartyIdentification>
          <cac:PartyLegalEntity>
            <cbc:RegistrationName>{{DESTINATARIO_NOMBRE}}</cbc:RegistrationName>
          </cac:PartyLegalEntity>
        </cac:DespatchParty>
      </cac:Despatch>
    </cac:Delivery>
    <cac:TransportHandlingUnit>
      <cac:TransportEquipment>
        <cbc:ID>{{VEHICULO_PLACA}}</cbc:ID>
      </cac:TransportEquipment>
    </cac:TransportHandlingUnit>
    {{VEHICULOS_SECUNDARIOS_BLOCK}}
  </cac:Shipment>
  {{DETALLE_ITEMS}}
</DespatchAdvice>';

    /**
     * Genera el XML de la GRE Transportista basado en el modelo Despatch
     */
    public function generateXml(Despatch $despatch): string
    {
        // Cargar todas las relaciones necesarias
        $despatch->loadMissing([
            'company',
            'client',
            'vehicle',
            'driver',
            'items.unitOfMeasure',
            'secondaryVehicles.driver',
            'relatedDocuments'
        ]);

        // Validaciones básicas
        $this->validateDespatchData($despatch);

        $xml = $this->xmlTemplate;

        // Reemplazos principales
        $replacements = [
            '{{SERIE}}' => $despatch->series,
            '{{NUMERO}}' => $despatch->number,
            '{{FECHA_EMISION}}' => $despatch->emission_date->format('Y-m-d'),
            '{{HORA_EMISION}}' => $despatch->emission_date->format('H:i:s'),
            '{{OBSERVACIONES_BLOCK}}' => $this->generateObservationsBlock($despatch->observations),

            // Transportista (tu empresa)
            '{{TRANSPORTISTA_RUC}}' => $despatch->company->ruc,
            '{{TRANSPORTISTA_NOMBRE}}' => $despatch->company->name,
            '{{TRANSPORTISTA_TIPO_DOC}}' => '6', // RUC

            // Remitente (No puede ser igual al transportista error 2560)
            '{{REMITENTE_RUC}}' => $despatch->company->ruc,
            '{{REMITENTE_NOMBRE}}' => $despatch->company->name,
            '{{REMITENTE_TIPO_DOC}}' => '6', // RUC

            // Destinatario (cliente)
            '{{DESTINATARIO_RUC}}' => $despatch->client->document_number,
            '{{DESTINATARIO_NOMBRE}}' => $despatch->client->name,
            '{{DESTINATARIO_TIPO_DOC}}' => '6',

            // Pesos
            '{{PESO_BRUTO}}' => number_format($despatch->total_gross_weight, 1, '.', ''),
            '{{PESO_UNIDAD}}' => $despatch->total_gross_weight_unit_of_measure,

            // Fechas
            '{{FECHA_INICIO_TRASLADO}}' => $despatch->transfer_start_date->format('Y-m-d'),

            // Conductor principal
            '{{CONDUCTOR_TIPO_DOC}}' => $this->mapDocumentType($despatch->driver->document_type),
            '{{CONDUCTOR_DOC}}' => $despatch->driver->document_number,
            '{{CONDUCTOR_NOMBRES}}' => $despatch->driver->first_name,
            '{{CONDUCTOR_APELLIDOS}}' => $despatch->driver->last_name,
            '{{LICENCIA_CONDUCIR}}' => $despatch->driver->license_number,

            // Vehículo principal
            '{{VEHICULO_PLACA}}' => $despatch->vehicle->plate_number,

            // Ubicaciones
            '{{ORIGEN_UBIGEO}}' => $despatch->departure_ubigeo,
            '{{ORIGEN_DIRECCION}}' => $despatch->departure_address,
            '{{DESTINO_UBIGEO}}' => $despatch->arrival_ubigeo,
            '{{DESTINO_DIRECCION}}' => $despatch->arrival_address,

            // Bloques dinámicos
            '{{CONDUCTORES_SECUNDARIOS_BLOCK}}' => $this->generateSecondaryDriversBlock($despatch),
            '{{VEHICULOS_SECUNDARIOS_BLOCK}}' => $this->generateSecondaryVehiclesBlock($despatch),
            '{{DETALLE_ITEMS}}' => $this->generateItemsBlock($despatch->items),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $xml);
    }

    /**
     * Valida que el despatch tenga todos los datos necesarios
     */
    protected function validateDespatchData(Despatch $despatch): void
    {
        if (!$despatch->company) {
            throw new Exception('La guía debe tener una empresa (transportista) asociada');
        }

        if (!$despatch->client) {
            throw new Exception('La guía debe tener un cliente (destinatario) asociado');
        }

        if (!$despatch->vehicle) {
            throw new Exception('La guía debe tener un vehículo principal asociado');
        }

        if (!$despatch->driver) {
            throw new Exception('La guía debe tener un conductor principal asociado');
        }

        if ($despatch->items->isEmpty()) {
            throw new Exception('La guía debe tener al menos un ítem');
        }

        // Validar que todos los ítems tengan unidad de medida
        foreach ($despatch->items as $item) {
            if (!$item->unitOfMeasure) {
                throw new Exception("El ítem '{$item->description}' no tiene unidad de medida asociada");
            }
        }
    }

    /**
     * Genera el bloque de observaciones si existe
     */
    protected function generateObservationsBlock(?string $observations): string
    {
        if (empty($observations)) {
            return '';
        }

        return '<cbc:Note>' . htmlspecialchars($observations, ENT_XML1) . '</cbc:Note>';
    }

    /**
     * Genera el bloque de conductores secundarios
     */
    protected function generateSecondaryDriversBlock(Despatch $despatch): string
    {
        if ($despatch->secondaryVehicles->isEmpty()) {
            return '';
        }

        $secondaryDriversXml = '';

        // Obtener conductores únicos de vehículos secundarios
        $secondaryDrivers = $despatch->secondaryVehicles
            ->filter(function ($vehicle) {
                return $vehicle->driver !== null;
            })
            ->map(function ($vehicle) {
                return $vehicle->driver;
            })
            ->unique('id');

        foreach ($secondaryDrivers as $driver) {
            $secondaryDriversXml .= '
      <cac:DriverPerson>
        <cbc:ID schemeID="' . $this->mapDocumentType($driver->document_type) . '">' . htmlspecialchars($driver->document_number, ENT_XML1) . '</cbc:ID>
        <cbc:FirstName>' . htmlspecialchars($driver->first_name, ENT_XML1) . '</cbc:FirstName>
        <cbc:FamilyName>' . htmlspecialchars($driver->last_name, ENT_XML1) . '</cbc:FamilyName>
        <cbc:JobTitle>Secundario</cbc:JobTitle>
        <cac:IdentityDocumentReference>
          <cbc:ID>' . htmlspecialchars($driver->license_number, ENT_XML1) . '</cbc:ID>
        </cac:IdentityDocumentReference>
      </cac:DriverPerson>';
        }

        return $secondaryDriversXml;
    }

    /**
     * Genera el bloque de vehículos secundarios
     */
    protected function generateSecondaryVehiclesBlock(Despatch $despatch): string
    {
        if ($despatch->secondaryVehicles->isEmpty()) {
            return '';
        }

        $secondaryVehiclesXml = '';

        foreach ($despatch->secondaryVehicles as $vehicle) {
            $secondaryVehiclesXml .= '
    <cac:TransportHandlingUnit>
      <cac:TransportEquipment>
        <cbc:ID>' . htmlspecialchars($vehicle->plate_number, ENT_XML1) . '</cbc:ID>
      </cac:TransportEquipment>
    </cac:TransportHandlingUnit>';
        }

        return $secondaryVehiclesXml;
    }

    /**
     * Genera el bloque de ítems de la guía
     */
    protected function generateItemsBlock($items): string
    {
        $itemsXml = '';

        foreach ($items as $index => $item) {
            $lineNumber = $index + 1;
            $itemsXml .= '
  <cac:DespatchLine>
    <cbc:ID>' . $lineNumber . '</cbc:ID>
    <cbc:DeliveredQuantity unitCode="' . $item->unitOfMeasure->code . '" unitCodeListID="UN/ECE rec 20" unitCodeListAgencyName="United Nations Economic Commission for Europe">' . number_format($item->quantity, 1, '.', '') . '</cbc:DeliveredQuantity>
    <cac:OrderLineReference>
      <cbc:LineID>' . ($lineNumber + 1) . '</cbc:LineID>
    </cac:OrderLineReference>
    <cac:Item>
      <cbc:Description>' . htmlspecialchars($item->description, ENT_XML1) . '</cbc:Description>
      <cac:SellersItemIdentification>
        <cbc:ID>' . htmlspecialchars($item->code ?: 'SIN_CODIGO', ENT_XML1) . '</cbc:ID>
      </cac:SellersItemIdentification>
    </cac:Item>
  </cac:DespatchLine>';
        }

        return $itemsXml;
    }

    /**
     * Mapea los tipos de documento del sistema a códigos SUNAT
     */
    protected function mapDocumentType($type): string
    {
        return match (strtoupper($type)) {
            'DNI' => '1',
            'RUC' => '6',
            'CE', 'CARNET DE EXTRANJERÍA' => '4',
            'PASAPORTE' => '7',
            default => '0',
        };
    }

    /**
     * Genera el nombre del archivo XML
     */
    public function generateXmlFileName(Despatch $despatch): string
    {
        return "{$despatch->company->ruc}-31-{$despatch->series}-{$despatch->number}.xml";
    }

    /**
     * Valida que el XML generado sea válido
     */
    public function validateXml(string $xmlContent): bool
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xmlContent);

        // Validaciones básicas
        $xpath = new \DOMXPath($dom);

        // Verificar que tenga los elementos obligatorios
        $requiredElements = [
            '//cbc:ID',
            '//cbc:IssueDate',
            '//cac:DespatchSupplierParty',
            '//cac:DeliveryCustomerParty',
            '//cac:Shipment',
            '//cac:DespatchLine'
        ];

        foreach ($requiredElements as $element) {
            if ($xpath->query($element)->length === 0) {
                throw new Exception("XML inválido: elemento obligatorio {$element} no encontrado");
            }
        }

        return true;
    }
}
