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
        'advance_regularization',
        'advance_document_series',
        'advance_document_number',
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
