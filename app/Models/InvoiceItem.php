<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'unit_of_measure_id',
        'code',
        'description',
        'quantity',
        'unit_value',
        'unit_price',
        'discount',
        'subtotal',
        'igv_type',
        'igv',
        'total',
        'reference_value',
        'item_detraction_amount',
        'advance_regularization',
        'advance_document_series',
        'advance_document_number',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_value' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'igv' => 'decimal:2',
        'total' => 'decimal:2',
        'reference_value' => 'decimal:2',
        'item_detraction_amount' => 'decimal:2',
        'advance_regularization' => 'boolean',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function unitOfMeasure()
    {
        return $this->belongsTo(MeasureUnit::class, 'unit_of_measure_id');
    }
}
