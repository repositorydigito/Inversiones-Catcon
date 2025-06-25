<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrafficTicket extends Model
{
    protected $fillable = [
        'image_path',
        'driver_id',
        'vehicle_id',
    ];

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}
