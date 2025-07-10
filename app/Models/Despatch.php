<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Despatch extends Model
{
    protected $fillable = [
        'operation',
        'document_type',
        'series',
        'number',
        'company_id', // Remitente
        'emission_date',
        'observations',
        'total_gross_weight',
        'total_gross_weight_unit_of_measure',
        'transfer_start_date',
        'vehicle_id', // Vehículo principal
        'driver_id',  // Conductor principal
        'client_id',  // Destinatario
        'departure_ubigeo',
        'departure_address',
        'departure_sunat_establishment_code',
        'arrival_ubigeo',
        'arrival_address',
        'arrival_sunat_establishment_code',
        'sunat_envio_indicador',
        'subcontractor_document_type',
        'subcontractor_document_number',
        'subcontractor_denomination',
        'service_payer_document_type',
        'service_payer_document_number',
        'service_payer_denomination',
        'send_automatically_to_client',
        'pdf_format',
        'accepted_by_sunat',
        'sunat_description',
        'sunat_note',
        'sunat_response_code',
        'sunat_soap_error',
        'qr_code_string',
        'enlace_del_pdf',
        'enlace_del_xml',
        'enlace_del_cdr',
    ];

    protected $casts = [
        'emission_date' => 'date', 
        'transfer_start_date' => 'date',
        'accepted_by_sunat' => 'boolean',
    ];

    // Relación con la empresa remitente
    public function company()
    {
        return $this->belongsTo(Company::class);
    }
    // Relación con el vehículo principal
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
    // Relación con el conductor principal
    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }
    // Relación con el cliente (destinatario)
    public function client()
    {
        return $this->belongsTo(Client::class);
    }
    // Relación con los ítems de la guía de remisión
    public function items()
    {
        return $this->hasMany(DespatchItem::class);
    }
    // Relación con los documentos relacionados
    public function relatedDocuments()
    {
        return $this->hasMany(DespatchRelatedDocument::class);
    }
    // Relación de muchos a muchos con vehículos secundarios
    public function secondaryVehicles()
    {
        return $this->belongsToMany(Vehicle::class, 'despatch_secondary_vehicle', 'despatch_id', 'vehicle_id');
    }
    // Relación de muchos a muchos con conductores secundarios
    public function secondaryDrivers()
    {
        return $this->belongsToMany(Driver::class, 'despatch_secondary_driver', 'despatch_id', 'driver_id');
    }
    public function invoices()
    {
        return $this->belongsToMany(Invoice::class, 'invoice_despatch', 'despatch_id', 'invoice_id');
    }
}
