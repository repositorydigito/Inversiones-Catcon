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
        'net_weight',
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
        'departure_departamento',
        'departure_provincia',
        'departure_distrito',
        'arrival_departamento',
        'arrival_provincia',
        'arrival_distrito',
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

        //Gastos operativos
        'loading_point',
        'unloading_point',
        'departure_location',  
        'arrival_location', 
        'product',
        'supplier',
        'tolls',
        'loading_expenses',
        'travel_allowances',
        'variable_salary',
        'operations_manager',
        'security',

        'sunat_ticket',
        'xml_file_name',
        'zip_hash',
    ];

    protected $casts = [
        'emission_date' => 'date',
        'transfer_start_date' => 'date',
        'accepted_by_sunat' => 'boolean',
        'tolls' => 'decimal:2',
        'loading_expenses' => 'decimal:2',
        'travel_allowances' => 'decimal:2',
        'variable_salary' => 'decimal:2',
        'operations_manager' => 'decimal:2',
        'security' => 'decimal:2',
        'net_weight' => 'decimal:2',
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
        return $this->belongsToMany(Vehicle::class, 'despatch_secondary_vehicle', 'despatch_id', 'vehicle_id')->distinct();;
    }
    // Relación de muchos a muchos con conductores secundarios
    public function secondaryDrivers()
    {
        return $this->belongsToMany(Driver::class, 'despatch_secondary_driver', 'despatch_id', 'driver_id')->distinct();;
    }
    public function invoices()
    {
        return $this->belongsToMany(Invoice::class, 'invoice_despatch', 'despatch_id', 'invoice_id');
    }
    public function operationalExpenses()
    {
        return $this->hasMany(OperationalExpense::class);
    }

    public function getTotalOperationalExpensesAttribute(): float
    {
        return $this->tolls + $this->loading_expenses + $this->travel_allowances + $this->variable_salary;
    }

    // Métodos auxiliares para SUNAT
    public function hasSunatTicket(): bool
    {
        return !empty($this->sunat_ticket);
    }

    public function isAcceptedBySunat(): bool
    {
        return $this->accepted_by_sunat === true;
    }

    public function isPendingInSunat(): bool
    {
        return $this->hasSunatTicket() && !$this->isAcceptedBySunat();
    }

    public function hasError(): bool
    {
        return !empty($this->sunat_soap_error);
    }

    public function getStatusBadge(): string
    {
        if ($this->isAcceptedBySunat()) {
            return 'success';
        }
        
        if ($this->isPendingInSunat()) {
            return 'warning';
        }
        
        if ($this->hasError()) {
            return 'danger';
        }
        
        return 'secondary';
    }

    public function getStatusText(): string
    {
        if ($this->isAcceptedBySunat()) {
            return 'Aceptado por SUNAT';
        }
        
        if ($this->isPendingInSunat()) {
            return 'Pendiente en SUNAT';
        }
        
        if ($this->hasError()) {
            return 'Error en SUNAT';
        }
        
        return 'No enviado';
    }
}
