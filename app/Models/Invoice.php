<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
     protected $fillable = [
        'client_id',
        'series',
        'number',
        'invoice_type',
        'transaction_type',        
        'emission_date',
        'due_date',
        'currency',
        'exchange_rate',
        'igv_percentage',
        'global_discount',
        'total_discount',
        'total_advance',
        'total_taxable',
        'total_unaffected',
        'total_exonerated',
        'total_igv',
        'total_gratuitous',
        'total_other_charges',
        'total',
        'perception_type',
        'perception_taxable_base',
        'total_perception',
        'total_included_perception',
        'detraction',
        'observations',
        'document_to_modify_type',
        'document_to_modify_series',
        'document_to_modify_number',
        'credit_note_type',
        'debit_note_type',
        'send_automatically_to_sunat',
        'send_automatically_to_client',
        'unique_code',
        'payment_conditions',
        'payment_method',
        'vehicle_plate',
        'purchase_order_service',
        'custom_table_code',
        'pdf_format',
        'sunat_accepted',
        'sunat_description',
        'sunat_note',
        'sunat_response_code',
        'sunat_soap_error',
        'pdf_link',
        'xml_link', 
        'cdr_link',
        'nubefact_key',
        'pdf_zip_base64',
        'xml_zip_base64',
        'cdr_zip_base64',
        'qr_code_string',
        'barcode_string',
        'hash_code',
        'sunat_link',

        'detraction_service_code',
        'detraction_percentage',
        'detraction_payment_method',
        'detraction_bank_account',
        'tipo_carga',
        'distancia_km',
        'peso_toneladas',
        'retorno_vacio',
        'ubigeo',
    ];

    protected $casts = [
        'emission_date' => 'date',
        'due_date' => 'date',
        'detraction' => 'boolean',
        'send_automatically_to_sunat' => 'boolean',
        'send_automatically_to_client' => 'boolean',
        'sunat_accepted' => 'boolean',
        'detraction_percentage' => 'decimal:2',
        'detraction' => 'boolean',
        'distancia_km' => 'decimal:2',
        'peso_toneladas' => 'decimal:2',
        'retorno_vacio' => 'boolean',
    ];

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }
    
    public function installments()
    {
        return $this->hasMany(InvoiceInstallment::class)->ordered();
    }
    
    public function client()
    {
        return $this->belongsTo(Client::class);
    }
    public function despatches()
    {
        return $this->belongsToMany(Despatch::class, 'invoice_despatch', 'invoice_id', 'despatch_id');
    }
    public function accountReceivables()
    {
        return $this->hasMany(AccountReceivable::class);
    }

}
