<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeasureUnit extends Model
{
    protected $fillable = [
        'code',
        'description',
    ];

    public function invoiceItems()
    {
        return $this->hasMany(InvoiceItem::class, 'unit_of_measure_id');
    }
    public function despatchItems()
    {
        return $this->hasMany(DespatchItem::class, 'unit_of_measure_id');
    }
}
