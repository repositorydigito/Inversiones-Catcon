<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DespatchItem extends Model
{
    protected $fillable = [
        'despatch_id',
        'unit_of_measure_id',
        'code',
        'description',
        'quantity',
    ];

    // Relación inversa con la guía de remisión
    public function despatch()
    {
        return $this->belongsTo(Despatch::class);
    }
    public function measureUnit()
    {
        return $this->belongsTo(MeasureUnit::class, 'unit_of_measure_id');
    }
}
