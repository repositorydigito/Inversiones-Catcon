<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    protected $fillable = [
        'driver_id',
        'plate_number',
        'brand',
        'model',
        'vehicle_certificate',        
        'soat_expiration_date',
        'technical_review_expiration_date',
        'tuce_expiration_date',
    ];
    
    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }
}
