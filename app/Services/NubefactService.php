<?php

namespace App\Services;

use Illuminate\Support\Facades\Http; 
use App\Models\Invoice;
use App\Models\Client;
use App\Models\MeasureUnit;

class NubefactService
{
    protected string $apiUrl;
    protected string $apiToken;

    public function __construct()
    {
        $this->apiUrl = env('NUBEFACT_API_URL');
        $this->apiToken = env('NUBEFACT_API_TOKEN');
    }
    
    public function sendInvoice(array $payload): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Token token="' . $this->apiToken . '"',
            'Content-Type' => 'application/json',
        ])->post($this->apiUrl, $payload); 

        if ($response->failed()) {
            $statusCode = $response->status();
            $errorMessage = $response->body(); 
            throw new \Exception("Error al conectar con la API de Nubefact. Código: {$statusCode}, Mensaje: {$errorMessage}");
        }

        $decodedResponse = $response->json();

        if (isset($decodedResponse['errors'])) {
            $errorDetail = json_encode($decodedResponse['errors']);
            throw new \Exception("La API de Nubefact devolvió un error: " . $errorDetail);
        }
        if (isset($decodedResponse['aceptada_por_sunat']) && $decodedResponse['aceptada_por_sunat'] === false) {
             throw new \Exception("SUNAT rechazó el comprobante: " . ($decodedResponse['sunat_description'] ?? 'Sin descripción de error.'));
        }

        return $decodedResponse;
    }

    protected function mapDocumentType($type)
    {
        return match (strtoupper($type)) {
            'DNI' => '1',
            'RUC' => '6',
            'CE'  => '4', 
            //default => '0', 
        };
    }
    
    public function buildInvoicePayload(Invoice $invoice): array
    {        
        $invoice->loadMissing(['client', 'items.unitOfMeasure']);

        if (!$invoice->client) {
            throw new \Exception("La factura no tiene un cliente asociado.");
        }
        if ($invoice->items->isEmpty()) {
            throw new \Exception("La factura no tiene ítems.");
        }

        $currencyCode = $invoice->currency;

        $itemsPayload = $invoice->items->map(function ($item) {
            if (!$item->unitOfMeasure) {
                throw new \Exception("Ítem sin unidad de medida asociada: " . $item->description);
            }
            return [
                "unidad_de_medida" => $item->unitOfMeasure->code,
                "codigo"           => $item->code ?: '',
                "descripcion"      => $item->description,
                "cantidad"         => (float) $item->quantity,
                "valor_unitario"   => (float) $item->unit_value,
                "precio_unitario"  => (float) $item->unit_price,
                "descuento"        => (float) ($item->discount ?: 0),
                "subtotal"         => (float) $item->subtotal,
                "tipo_de_igv"      => $item->igv_type,
                "igv"              => (float) $item->igv,
                "total"            => (float) $item->total,
                "anticipo_regularizacion" => $item->advance_regularization ? 'true' : 'false',
                "anticipo_documento_serie" => $item->advance_document_series ?: '',
                "anticipo_documento_numero" => (float) ($item->advance_document_number ?: 0),
            ];
        })->toArray();

        $payload = [
            "operacion"                 => "generar_comprobante",
            "tipo_de_comprobante"       => $invoice->invoice_type,
            "serie"                     => $invoice->series,
            "numero"                    => (int) $invoice->number,
            "sunat_transaction"         => $invoice->transaction_type,
            "cliente_tipo_de_documento" => $this->mapDocumentType($invoice->client->document_type),
            "cliente_numero_de_documento" => $invoice->client->document_number,
            "cliente_denominacion"      => $invoice->client->name,
            "cliente_direccion"         => $invoice->client->address ?: '',
            "cliente_email"             => $invoice->client->email ?: '',
            "cliente_email_1"           => "",
            "cliente_email_2"           => "",
            "fecha_de_emision"          => $invoice->emission_date->format('d-m-Y'),
            "fecha_de_vencimiento"      => $invoice->due_date ? $invoice->due_date->format('d-m-Y') : '',
            "moneda"                    => $currencyCode,
            "porcentaje_de_igv"         => (float) $invoice->igv_percentage,
            "descuento_global"          => (float) ($invoice->global_discount ?: 0),
            "total_descuento"           => (float) ($invoice->total_discount ?: 0),
            "total_anticipo"            => (float) ($invoice->total_advance ?: 0),
            "total_gravada"             => (float) $invoice->total_taxable,
            "total_inafecta"            => (float) ($invoice->total_unaffected ?: 0),
            "total_exonerada"           => (float) ($invoice->total_exonerated ?: 0),
            "total_igv"                 => (float) $invoice->total_igv,
            "total_gratuita"             => (float) ($invoice->total_gratuitous ?: 0),
            "total_otros_cargos"        => (float) ($invoice->total_other_charges ?: 0),
            "total"                     => (float) $invoice->total,

            "tipo_de_percepcion"        => $invoice->perception_type ?: '',
            "base_imponible_de_percepcion" => (float) ($invoice->perception_taxable_base ?: 0),
            "total_percepcion"          => (float) ($invoice->total_perception ?: 0),
            "total_incluido_percepcion" => (float) ($invoice->total_included_perception ?: 0),
            "detraccion"                => $invoice->detraction ? 'true' : 'false',

            "observaciones"             => $invoice->observations ?: '',
            "documento_que_se_modifica_tipo" => $invoice->document_to_modify_type ?: '',
            "documento_que_se_modifica_serie" => $invoice->document_to_modify_series ?: '',
            "documento_que_se_modifica_numero" => (int) ($invoice->document_to_modify_number ?: 0),
            "tipo_de_nota_de_credito"   => $invoice->credit_note_type ?: '',
            "tipo_de_nota_de_debito"    => $invoice->debit_note_type ?: '',

            "enviar_automaticamente_a_la_sunat" => $invoice->send_automatically_to_sunat,
            "enviar_automaticamente_al_cliente" => $invoice->send_automatically_al_cliente,
            "codigo_unico"              => $invoice->unique_code ?: '',
            "condiciones_de_pago"       => $invoice->payment_conditions ?: '',
            "medio_de_pago"             => $invoice->payment_method ?: '',
            "placa_vehiculo"            => $invoice->vehicle_plate ?: '',
            "orden_compra_servicio"     => $invoice->purchase_order_service ?: '',
            "formato_de_pdf"            => $invoice->pdf_format ?: '',

            "items"                     => $itemsPayload,
        ];

        return $payload;
    }
}