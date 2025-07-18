<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DespatchItem extends Model
{
    protected $fillable = [
        'despatch_id',
        'unit_of_measure_id',
        'service_id',
        'code',
        'description',
        'quantity',
    ];

    public function despatch()
    {
        return $this->belongsTo(Despatch::class);
    }
    public function unitOfMeasure()
    {
        return $this->belongsTo(MeasureUnit::class, 'unit_of_measure_id');
    }
    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    // Accessor para obtener código del servicio o campo code
    public function getServiceCodeAttribute(): ?string
    {
        return $this->service ? $this->service->code : $this->code;
    }
    // Accessor para obtener descripción del servicio o campo description
    public function getServiceDescriptionAttribute(): ?string
    {
        return $this->service ? $this->service->name : $this->description;
    }
}
